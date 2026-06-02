<?php

namespace App\Livewire;

use App\Enums\TaskStatus;
use App\Jobs\RunTaskJob;
use App\Models\Approval;
use App\Models\Profile;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Inbox extends Component
{
    public Profile $profile;

    /**
     * Statuses that keep a task visible until a human acts on it.
     *
     * @var array<int, TaskStatus>
     */
    public const ATTENTION_STATUSES = [
        TaskStatus::Review,
        TaskStatus::NeedsInput,
        TaskStatus::Blocked,
    ];

    public function mount(Profile $profile): void
    {
        $this->profile = $profile;
    }

    public function grant(int $approvalId): void
    {
        $approval = $this->pendingApproval($approvalId);

        if ($approval === null) {
            return;
        }

        $approval->update([
            'decision' => 'granted',
            'decided_at' => now(),
        ]);

        $task = $approval->task;
        $task->recordEvent('approval_granted', [
            'capability' => $approval->capability,
        ]);

        // Re-run with the granted capability now permitted. A review task is
        // promoted back to ready first so the standard claim path applies.
        if ($task->status === TaskStatus::Review) {
            $task->update(['status' => TaskStatus::Ready]);
            $task->recordEvent('status_changed', [
                'from' => TaskStatus::Review->value,
                'to' => TaskStatus::Ready->value,
            ]);
        }

        if ($task->claim()) {
            RunTaskJob::dispatch($task);
        }
    }

    public function deny(int $approvalId): void
    {
        $approval = $this->pendingApproval($approvalId);

        if ($approval === null) {
            return;
        }

        $approval->update([
            'decision' => 'denied',
            'decided_at' => now(),
        ]);

        $approval->task->recordEvent('approval_denied', [
            'capability' => $approval->capability,
        ]);
    }

    /**
     * A pending approval belonging to this profile, or null.
     */
    private function pendingApproval(int $approvalId): ?Approval
    {
        return Approval::query()
            ->pending()
            ->whereKey($approvalId)
            ->whereHas('task', fn ($query) => $query->where('profile_id', $this->profile->id))
            ->first();
    }

    public function render()
    {
        $tasks = $this->profile->tasks()
            ->whereIn('status', array_map(fn (TaskStatus $s) => $s->value, self::ATTENTION_STATUSES))
            ->with(['events' => fn ($query) => $query->limit(1)])
            ->orderByDesc('updated_at')
            ->get();

        $approvals = Approval::query()
            ->pending()
            ->whereHas('task', fn ($query) => $query->where('profile_id', $this->profile->id))
            ->with('task')
            ->orderByDesc('id')
            ->get();

        return view('livewire.inbox', [
            'tasks' => $tasks,
            'approvals' => $approvals,
        ]);
    }
}
