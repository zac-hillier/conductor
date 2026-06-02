<?php

namespace App\Livewire;

use App\Enums\TaskStatus;
use App\Jobs\RunTaskJob;
use App\Jobs\ScoreTaskJob;
use App\Models\Profile;
use App\Models\Task;
use App\Models\TaskComment;
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
        $oldPriority = $task->priority;

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
        ]);

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
        $prefix = strtoupper(substr($this->profile->slug, 0, 4));
        $seq = $this->profile->tasks()->count() + 1;

        return sprintf('%s-%03d', $prefix, $seq);
    }

    public function render()
    {
        $tasks = $this->profile->tasks()
            ->with('project')
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->get()
            ->groupBy(fn (Task $task) => $task->status->value);

        $selectedTask = $this->selectedTaskId !== null
            ? $this->profile->tasks()->with(['events', 'runs', 'comments', 'project'])->find($this->selectedTaskId)
            : null;

        $projects = $this->profile->projects()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return view('livewire.board', [
            'columns' => TaskStatus::ordered(),
            'tasks' => $tasks,
            'statuses' => TaskStatus::ordered(),
            'selectedTask' => $selectedTask,
            'projects' => $projects,
            'availableDocs' => $this->availableDocs($selectedTask),
        ]);
    }
}
