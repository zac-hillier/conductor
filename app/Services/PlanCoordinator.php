<?php

namespace App\Services;

use App\Enums\PhaseStatus;
use App\Enums\PlanStatus;
use App\Enums\TaskStatus;
use App\Jobs\ReviewPhaseJob;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\Task;

/**
 * The deterministic coordinator for multi-phase plans: keeps a phase row in step
 * with its backing task, gates phase N+1 on phase N, runs the optional ag-review
 * gate, and refreshes the project pulse after living-doc write-back.
 */
class PlanCoordinator
{
    /**
     * Called whenever a phase-backing task changes terminal state (from the
     * runner or a board action). No-op for ordinary tasks.
     */
    public function onTaskSettled(?Task $task): void
    {
        $phase = $task?->phase;

        if ($phase === null) {
            return;
        }

        $this->syncPhase($phase, $task);
        $phase->refresh();
        $plan = $phase->plan;

        if ($plan->status === PlanStatus::Ready && $phase->status !== PhaseStatus::Pending) {
            $plan->update(['status' => PlanStatus::Executing]);
        }

        if ($phase->status === PhaseStatus::Blocked) {
            $plan->update(['status' => PlanStatus::Blocked]);

            return;
        }

        if ($phase->status !== PhaseStatus::Done) {
            return;
        }

        // The phase's worker maintained the project living docs — refresh the
        // discovered context so the pulse reflects it.
        $plan->project->discoverContext();

        // Optional automated review gate; otherwise advance immediately.
        if ((bool) config('conductor.pipeline.review.enabled') && $phase->review_verdict === null) {
            ReviewPhaseJob::dispatch($phase);

            return;
        }

        $this->advance($plan);
    }

    /**
     * Make the next pending phase executable (creating its board task), or
     * complete the plan when every phase is done.
     */
    public function advance(Plan $plan): void
    {
        $next = $plan->phases()
            ->where('status', PhaseStatus::Pending->value)
            ->orderBy('number')
            ->first();

        if ($next !== null && $next->isExecutable()) {
            $next->createBackingTask();
            $plan->update(['status' => PlanStatus::Executing]);

            return;
        }

        $outstanding = $plan->phases()
            ->where('status', '!=', PhaseStatus::Done->value)
            ->exists();

        if (! $outstanding) {
            $plan->update(['status' => PlanStatus::Complete]);
        }
    }

    private function syncPhase(Phase $phase, Task $task): void
    {
        $run = $task->runs()->first();

        $status = match ($task->status) {
            TaskStatus::Review => PhaseStatus::Review,
            TaskStatus::Complete => PhaseStatus::Done,
            TaskStatus::Blocked => PhaseStatus::Blocked,
            TaskStatus::Processing => PhaseStatus::InProgress,
            default => $phase->status,
        };

        $updates = [
            'status' => $status,
            'outcome' => $run?->outcome,
            'cost' => $run?->cost,
        ];

        if ($status === PhaseStatus::Done && $phase->finished_at === null) {
            $updates['finished_at'] = now();
        }

        $phase->update($updates);
    }
}
