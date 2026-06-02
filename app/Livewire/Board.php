<?php

namespace App\Livewire;

use App\Enums\TaskStatus;
use App\Jobs\RunTaskJob;
use App\Jobs\ScopeTaskJob;
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

    public function mount(Profile $profile): void
    {
        $this->profile = $profile;
    }

    public function createTask(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string'],
            'priority' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $task = $this->profile->tasks()->create([
            'ref' => $this->nextRef(),
            'title' => $validated['title'],
            'summary' => $validated['summary'] ?: null,
            'priority' => $validated['priority'],
            'status' => TaskStatus::Backlog,
        ]);

        $task->recordEvent('created');

        $this->reset('title', 'summary', 'priority', 'showCreate');
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
    }

    public function selectTask(int $taskId): void
    {
        $task = $this->profile->tasks()->findOrFail($taskId);

        $this->selectedTaskId = $task->id;
        $this->editTitle = $task->title;
        $this->editSummary = $task->summary ?? '';
        $this->editPriority = $task->priority;
        $this->editStatus = $task->status->value;
        $this->showDetail = true;
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

        if ($task->claim()) {
            RunTaskJob::dispatch($task);
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
    }

    private function beginScoping(Task $task): void
    {
        $from = $task->status;
        $task->update(['status' => TaskStatus::Scoping]);
        $task->recordEvent('status_changed', [
            'from' => $from->value,
            'to' => TaskStatus::Scoping->value,
        ]);

        ScopeTaskJob::dispatch($task);
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
    }

    private function selectedReviewTask(): ?Task
    {
        if ($this->selectedTaskId === null) {
            return null;
        }

        $task = $this->profile->tasks()->findOrFail($this->selectedTaskId);

        return $task->status === TaskStatus::Review ? $task : null;
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
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->get()
            ->groupBy(fn (Task $task) => $task->status->value);

        $selectedTask = $this->selectedTaskId !== null
            ? $this->profile->tasks()->with(['events', 'runs', 'comments'])->find($this->selectedTaskId)
            : null;

        return view('livewire.board', [
            'columns' => TaskStatus::ordered(),
            'tasks' => $tasks,
            'statuses' => TaskStatus::ordered(),
            'selectedTask' => $selectedTask,
        ]);
    }
}
