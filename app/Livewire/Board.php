<?php

namespace App\Livewire;

use App\Enums\TaskStatus;
use App\Jobs\GeneratePlanJob;
use App\Jobs\RunTaskJob;
use App\Jobs\ScoreTaskJob;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Task;
use App\Models\TaskComment;
use App\Services\PlanCoordinator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Board extends Component
{
    public Profile $profile;

    // Create modal state.
    public bool $showCreate = false;

    public string $title = '';

    public string $summary = '';

    public int $priority = 50;

    // Project the new task will belong to (defaults to the profile's default).
    public ?int $createProjectId = null;

    // Drawer / edit state.
    public ?int $selectedTaskId = null;

    // Controls the detail flyout open/close (a boolean — never bind the int
    // $selectedTaskId to the modal, or its open-state casts true->1 and clobbers
    // the selected id).
    public bool $showDetail = false;

    public string $editTitle = '';

    public string $editSummary = '';

    public int $editPriority = 50;

    public string $editStatus = TaskStatus::Backlog->value;

    public ?int $editProjectId = null;

    // "Relevant to phase" link (tasks.phase_id); null = none.
    public ?int $editPhaseId = null;

    // Prerequisite picker selection (Depends-on UI).
    public ?int $dependencyToAdd = null;

    public string $reviewNote = '';

    public string $scopeAnswer = '';

    // A transient, human-facing note explaining what an action did (or why it
    // was refused) — the drawer's way of narrating background work back.
    public ?string $actionNotice = null;

    public function mount(Profile $profile): void
    {
        $this->profile = $profile;
    }

    /**
     * Poll faster while work is in flight, slower when idle. Used by the board's
     * wire:poll so running/scoping tasks update live without a manual refresh.
     */
    public function pollInterval(): string
    {
        $inFlight = $this->profile->tasks()
            ->whereIn('status', [TaskStatus::Processing->value, TaskStatus::Scoping->value])
            ->exists();

        return $inFlight ? '3s' : '10s';
    }

    /**
     * Whether the board should be polling right now. Polls on the board, and
     * while the drawer is open ONLY on a scoping task (so the agent's questions
     * arrive live). Paused for the create modal and for editing/answering a
     * non-scoping task, so typing/focus isn't disrupted.
     */
    public function shouldPoll(): bool
    {
        if ($this->showCreate) {
            return false;
        }

        if ($this->showDetail) {
            return $this->selectedTaskId !== null
                && $this->profile->tasks()
                    ->whereKey($this->selectedTaskId)
                    ->where('status', TaskStatus::Scoping->value)
                    ->exists();
        }

        return true;
    }

    public function createTask(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string'],
            'priority' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $projectId = $this->createProjectId
            ?? $this->profile->defaultProject()?->id;

        $task = $this->profile->tasks()->create([
            'project_id' => $projectId,
            'ref' => $this->nextRef(),
            'title' => $validated['title'],
            'summary' => $validated['summary'] ?: null,
            'priority' => $validated['priority'],
            'status' => TaskStatus::Backlog,
        ]);

        $task->recordEvent('created');

        $this->reset('title', 'summary', 'priority', 'showCreate', 'createProjectId');
        $this->priority = 50;
    }

    public function moveTask(int $taskId, string $toStatus): void
    {
        $target = TaskStatus::tryFrom($toStatus);

        if ($target === null) {
            return;
        }

        $task = $this->profile->tasks()->findOrFail($taskId);
        $from = $task->status;

        if ($from === $target) {
            return;
        }

        // Unmet prerequisites park the task — deny a drag into an active column.
        if (in_array($target, [TaskStatus::Scoping, TaskStatus::Ready, TaskStatus::Processing], true)
            && $task->isBlockedByDependencies()) {
            return;
        }

        $task->update(['status' => $target]);

        $task->recordEvent('status_changed', [
            'from' => $from->value,
            'to' => $target->value,
        ]);

        if ($target === TaskStatus::Ready) {
            $task->enterReady();
        }

        if ($target === TaskStatus::Scoping) {
            $task->enterScoping();
        }
    }

    public function selectTask(int $taskId): void
    {
        $task = $this->profile->tasks()->findOrFail($taskId);

        $this->selectedTaskId = $task->id;
        $this->syncEditFields($task);
        $this->actionNotice = null;
        $this->showDetail = true;
    }

    /**
     * Re-hydrate the edit form from the task's current truth. Called on select
     * and after every action that mutates the task, so the form never lags
     * behind a status the coordinator changed in the background.
     */
    private function syncEditFields(Task $task): void
    {
        $this->editTitle = $task->title;
        $this->editSummary = $task->summary ?? '';
        $this->editPriority = $task->priority;
        $this->editStatus = $task->status->value;
        $this->editProjectId = $task->project_id;
        $this->editPhaseId = $task->phase_id;
    }

    /**
     * Validate the chosen "relevant to phase" id belongs to a plan in this
     * profile; otherwise treat it as cleared.
     */
    private function resolveRelevantPhaseId(): ?int
    {
        if ($this->editPhaseId === null) {
            return null;
        }

        $valid = Phase::query()
            ->whereKey($this->editPhaseId)
            ->whereHas('plan.project', fn ($q) => $q->where('profile_id', $this->profile->id))
            ->exists();

        return $valid ? $this->editPhaseId : null;
    }

    public function updatedShowDetail(bool $value): void
    {
        if (! $value) {
            $this->selectedTaskId = null;
        }
    }

    public function updateTask(): void
    {
        $validated = $this->validate([
            'editTitle' => ['required', 'string', 'max:255'],
            'editSummary' => ['nullable', 'string'],
            'editPriority' => ['required', 'integer', 'min:1', 'max:100'],
            'editStatus' => ['required', Rule::enum(TaskStatus::class)],
        ]);

        $task = $this->profile->tasks()->findOrFail($this->selectedTaskId);

        // Guard against clobbering background-owned work: if a worker is running
        // or a scope run is in flight, the form's status is stale. Re-sync to
        // truth and refuse the save rather than overwrite live state.
        if ($task->status === TaskStatus::Processing || $task->hasActiveScopeRun()) {
            $this->syncEditFields($task);
            $this->actionNotice = 'This task is being worked on in the background — your changes were not saved, to avoid overwriting it.';

            return;
        }

        $newStatus = TaskStatus::from($validated['editStatus']);
        $oldStatus = $task->status;

        // Unmet prerequisites block a move into scoping/ready/processing.
        if ($newStatus !== $oldStatus
            && in_array($newStatus, [TaskStatus::Scoping, TaskStatus::Ready, TaskStatus::Processing], true)
            && $task->isBlockedByDependencies()) {
            $this->syncEditFields($task);
            $this->actionNotice = 'Blocked: complete this task\'s prerequisites before moving it there.';

            return;
        }

        $oldPriority = $task->priority;
        $oldProjectId = $task->project_id;

        // Project reassignment (Part A): refused for a phase execution task,
        // which is pinned to its plan's project.
        $projectId = $task->project_id;
        if ($this->editProjectId !== $task->project_id) {
            if ($task->isPhaseExecutionTask()) {
                $this->actionNotice = "This task executes a plan phase, so it stays in its plan's project.";
            } elseif ($this->editProjectId !== null && $this->profile->projects()->whereKey($this->editProjectId)->exists()) {
                $projectId = $this->editProjectId;
            }
        }

        // Phase relevance (Part B): a visual tag; not editable for execution tasks.
        $phaseId = $task->isPhaseExecutionTask()
            ? $task->phase_id
            : $this->resolveRelevantPhaseId();

        $changedFields = [];

        if ($task->title !== $validated['editTitle']) {
            $changedFields[] = 'title';
        }

        if (($task->summary ?? '') !== ($validated['editSummary'] ?? '')) {
            $changedFields[] = 'summary';
        }

        $task->update([
            'title' => $validated['editTitle'],
            'summary' => $validated['editSummary'] ?: null,
            'priority' => $validated['editPriority'],
            'status' => $newStatus,
            'project_id' => $projectId,
            'phase_id' => $phaseId,
        ]);

        if ($projectId !== $oldProjectId) {
            $task->recordEvent('project_changed', ['from' => $oldProjectId, 'to' => $projectId]);
        }

        if ($changedFields !== []) {
            $task->recordEvent('updated', ['fields' => $changedFields]);
        }

        if ($oldPriority !== $validated['editPriority']) {
            $task->recordEvent('priority_changed', [
                'from' => $oldPriority,
                'to' => $validated['editPriority'],
            ]);
        }

        if ($oldStatus !== $newStatus) {
            $task->recordEvent('status_changed', [
                'from' => $oldStatus->value,
                'to' => $newStatus->value,
            ]);

            if ($newStatus === TaskStatus::Ready) {
                $task->enterReady();
            }

            if ($newStatus === TaskStatus::Scoping) {
                $task->enterScoping();
            }
        }
    }

    public function dispatchTask(): void
    {
        if ($this->selectedTaskId === null) {
            return;
        }

        $task = $this->profile->tasks()->findOrFail($this->selectedTaskId);

        if ($task->status !== TaskStatus::Ready) {
            return;
        }

        if (! $this->profile->hasValidWorkdir()) {
            $this->actionNotice = 'This profile has no valid project home. Set one in Settings before dispatching.';

            return;
        }

        if ($task->isBlockedByDependencies()) {
            $this->actionNotice = 'Blocked: complete this task\'s prerequisites first.';

            return;
        }

        if ($task->claim()) {
            RunTaskJob::dispatch($task);

            // Hand the human back to the board with the task visibly running in
            // the Processing column, rather than leaving a stale edit form open.
            $this->showDetail = false;
            $this->selectedTaskId = null;
            $this->actionNotice = null;
        }
    }

    public function scoreTask(): void
    {
        if ($this->selectedTaskId === null) {
            return;
        }

        $task = $this->profile->tasks()->findOrFail($this->selectedTaskId);

        if ($task->status !== TaskStatus::Ready) {
            return;
        }

        ScoreTaskJob::dispatch($task);
        $task->recordEvent('score_requested');
    }

    public function scopeTask(): void
    {
        if ($this->selectedTaskId === null) {
            return;
        }

        $task = $this->profile->tasks()->findOrFail($this->selectedTaskId);

        if (! in_array($task->status, [TaskStatus::Backlog, TaskStatus::Research], true)) {
            return;
        }

        if ($task->isBlockedByDependencies()) {
            $this->actionNotice = 'Blocked: complete this task\'s prerequisites first.';

            return;
        }

        $this->beginScoping($task);

        $this->syncEditFields($task);
        $this->actionNotice = 'Scoping started — an agent is interrogating this task. Its questions will appear here.';
    }

    public function rescope(): void
    {
        if ($this->selectedTaskId === null) {
            return;
        }

        $task = $this->profile->tasks()->findOrFail($this->selectedTaskId);

        if ($task->status !== TaskStatus::Scoping) {
            return;
        }

        $task->enterScoping();

        $this->syncEditFields($task->fresh());
        $this->actionNotice = 'Scoping re-queued — an agent will pick this up shortly.';
    }

    public function continueScoping(): void
    {
        if ($this->selectedTaskId === null) {
            return;
        }

        $task = $this->profile->tasks()->findOrFail($this->selectedTaskId);

        if ($task->status !== TaskStatus::NeedsInput) {
            return;
        }

        $answer = trim($this->scopeAnswer);

        if ($answer === '') {
            return;
        }

        $task->comments()->create([
            'author' => TaskComment::AUTHOR_HUMAN,
            'body' => $answer,
        ]);

        $this->reset('scopeAnswer');

        $this->beginScoping($task);

        $this->syncEditFields($task);
        $this->actionNotice = 'Answer sent — scoping resumed with your input.';
    }

    private function beginScoping(Task $task): void
    {
        $from = $task->status;
        $task->update(['status' => TaskStatus::Scoping]);
        $task->recordEvent('status_changed', [
            'from' => $from->value,
            'to' => TaskStatus::Scoping->value,
        ]);

        $task->enterScoping();
    }

    public function approveTask(): void
    {
        $task = $this->selectedReviewTask();

        if ($task === null) {
            return;
        }

        $task->update(['status' => TaskStatus::Complete]);
        $task->recordEvent('status_changed', [
            'from' => TaskStatus::Review->value,
            'to' => TaskStatus::Complete->value,
        ]);
        $task->recordEvent('approved');

        // Advance the plan if this task backs a phase; notify any dependents
        // this completion unblocks.
        app(PlanCoordinator::class)->onTaskSettled($task->fresh());
        $task->fresh()->notifyUnblockedDependents();

        $this->syncEditFields($task->fresh());
        $this->actionNotice = 'Approved — task marked complete.';
    }

    public function requestChanges(): void
    {
        $task = $this->selectedReviewTask();

        if ($task === null) {
            return;
        }

        $note = trim($this->reviewNote);

        if ($note !== '') {
            $task->comments()->create(['body' => $note]);
        }

        $task->update(['status' => TaskStatus::Ready]);
        $task->recordEvent('status_changed', [
            'from' => TaskStatus::Review->value,
            'to' => TaskStatus::Ready->value,
        ]);
        $task->recordEvent('changes_requested', $note !== '' ? ['note' => $note] : null);

        $this->reset('reviewNote');

        $this->syncEditFields($task->fresh());
        $this->actionNotice = 'Changes requested — task moved back to Ready.';
    }

    public function retryTask(): void
    {
        if ($this->selectedTaskId === null) {
            return;
        }

        $task = $this->profile->tasks()->findOrFail($this->selectedTaskId);

        if ($task->status !== TaskStatus::Blocked) {
            return;
        }

        $task->update(['status' => TaskStatus::Ready]);
        $task->recordEvent('status_changed', [
            'from' => TaskStatus::Blocked->value,
            'to' => TaskStatus::Ready->value,
        ]);
        $task->recordEvent('retry_requested');

        $task->enterReady();

        $this->syncEditFields($task->fresh());
        $this->actionNotice = 'Moved back to Ready for another attempt.';
    }

    private function selectedReviewTask(): ?Task
    {
        if ($this->selectedTaskId === null) {
            return null;
        }

        $task = $this->profile->tasks()->findOrFail($this->selectedTaskId);

        return $task->status === TaskStatus::Review ? $task : null;
    }

    /**
     * Pin or unpin a discovered reference/docs file for the selected task, so
     * the context builder always includes its body in that task's prompts.
     */
    public function togglePin(string $path): void
    {
        if ($this->selectedTaskId === null) {
            return;
        }

        $task = $this->profile->tasks()->findOrFail($this->selectedTaskId);
        $pinned = $task->pinned_docs ?? [];

        $pinned = in_array($path, $pinned, true)
            ? array_values(array_diff($pinned, [$path]))
            : [...$pinned, $path];

        $task->update(['pinned_docs' => $pinned]);
    }

    /**
     * Reference + docs files discovered for a task's project, for the drawer's
     * pin toggles.
     *
     * @return array<int, array{path: string, role: string, name: string}>
     */
    private function availableDocs(?Task $task): array
    {
        $map = $task?->project?->context_map ?? [];
        $docs = [];

        foreach (['reference', 'docs'] as $role) {
            foreach ($map['roles'][$role] ?? [] as $dir) {
                foreach (glob(rtrim((string) $dir, '/').'/*.md') ?: [] as $file) {
                    $docs[] = ['path' => $file, 'role' => $role, 'name' => basename($file)];

                    if (count($docs) >= 50) {
                        return $docs;
                    }
                }
            }
        }

        return $docs;
    }

    /**
     * Add a prerequisite to the selected task (profile-scoped, no cycles).
     */
    public function addDependency(): void
    {
        if ($this->selectedTaskId === null || $this->dependencyToAdd === null) {
            return;
        }

        $task = $this->profile->tasks()->findOrFail($this->selectedTaskId);
        $prerequisite = $this->profile->tasks()->find($this->dependencyToAdd);

        if ($prerequisite === null) {
            return;
        }

        if ($task->wouldCycleWith($prerequisite)) {
            $this->actionNotice = 'That would create a circular dependency.';
            $this->dependencyToAdd = null;

            return;
        }

        $task->dependencies()->syncWithoutDetaching([$prerequisite->id]);
        $this->dependencyToAdd = null;
    }

    public function removeDependency(int $prerequisiteId): void
    {
        if ($this->selectedTaskId === null) {
            return;
        }

        $this->profile->tasks()->findOrFail($this->selectedTaskId)
            ->dependencies()->detach($prerequisiteId);
    }

    /**
     * Promote the selected task into a multi-phase plan: a plan is created in
     * the task's project (seeded from its brief) and the planning pipeline runs.
     * The board is where a build is born and decomposed.
     */
    public function promoteToPlan()
    {
        if ($this->selectedTaskId === null) {
            return;
        }

        $task = $this->profile->tasks()->with('project')->findOrFail($this->selectedTaskId);
        $project = $task->project ?? $this->profile->defaultProject();

        if ($project === null) {
            $this->actionNotice = 'This profile has no project to attach a plan to.';

            return;
        }

        $concept = trim($task->title."\n\n".($task->summary ?? ''));

        $plan = $project->plans()->create([
            'source_task_id' => $task->id,
            'name' => $task->title,
            'slug' => Plan::uniqueSlugFor($project, $task->title),
            'concept' => $concept,
        ]);

        GeneratePlanJob::dispatch($plan);

        return $this->redirect(route('profiles.plans.show', [$this->profile, $plan]), navigate: true);
    }

    public function deleteTask(): void
    {
        if ($this->selectedTaskId === null) {
            return;
        }

        $this->profile->tasks()->findOrFail($this->selectedTaskId)->delete();

        $this->selectedTaskId = null;
        $this->showDetail = false;
    }

    private function nextRef(): string
    {
        return $this->profile->nextTaskRef();
    }

    public function render()
    {
        $tasks = $this->profile->tasks()
            ->with(['project', 'phase.plan'])
            ->withCount(['dependencies as unmet_dependencies_count' => function ($query) {
                $query->whereNotIn('status', [TaskStatus::Complete->value, TaskStatus::Archived->value]);
            }])
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->get()
            ->groupBy(fn (Task $task) => $task->status->value);

        $selectedTask = $this->selectedTaskId !== null
            ? $this->profile->tasks()->with(['events', 'runs', 'comments', 'project', 'phase.plan', 'dependencies.project'])->find($this->selectedTaskId)
            : null;

        $dependencyOptions = $selectedTask !== null
            ? $this->profile->tasks()
                ->where('id', '!=', $selectedTask->id)
                ->whereNotIn('id', $selectedTask->dependencies->pluck('id'))
                ->orderBy('ref')
                ->get()
            : collect();

        $projects = $this->profile->projects()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $phaseOptions = Phase::query()
            ->whereHas('plan.project', fn ($q) => $q->where('profile_id', $this->profile->id))
            ->with('plan:id,name')
            ->orderBy('plan_id')
            ->orderBy('number')
            ->get();

        return view('livewire.board', [
            'columns' => TaskStatus::ordered(),
            'tasks' => $tasks,
            'statuses' => TaskStatus::ordered(),
            'selectedTask' => $selectedTask,
            'projects' => $projects,
            'phaseOptions' => $phaseOptions,
            'dependencyOptions' => $dependencyOptions,
            'availableDocs' => $this->availableDocs($selectedTask),
        ]);
    }
}
