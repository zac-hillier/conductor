<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Jobs\RunTaskJob;
use App\Jobs\ScoreTaskJob;
use App\Models\Profile;
use App\Models\Task;
use Illuminate\Support\Collection;

class DispatchScheduler
{
    /**
     * Walk every auto-dispatch profile and fill its free concurrency slots
     * with the highest-priority ready tasks, claiming and queueing each one.
     *
     * @return array<int, array{
     *     profile: string,
     *     cap: int,
     *     inflight: int,
     *     slots: int,
     *     dispatched: array<int, string>,
     * }>
     */
    public function tick(bool $dryRun = false): array
    {
        $plan = [];

        $profiles = Profile::query()
            ->with('policy')
            ->orderBy('id')
            ->get();

        foreach ($profiles as $profile) {
            if (! $profile->policyOrDefault()->autoDispatch()) {
                continue;
            }

            $cap = max(1, (int) $profile->concurrency_cap);

            $inflight = $profile->tasks()
                ->where('status', TaskStatus::Processing->value)
                ->count();

            $slots = max(0, $cap - $inflight);

            $candidates = $slots > 0
                ? $this->candidates($profile, $slots)
                : new Collection;

            $dispatched = [];

            foreach ($candidates as $task) {
                // Never auto-dispatch a task without a valid resolved working
                // directory — the worker would run in the wrong place. Leave it
                // ready; manual dispatch surfaces the same guard on-screen.
                if (! $task->hasValidWorkdir()) {
                    continue;
                }

                // Don't auto-dispatch a task whose prerequisites are unmet.
                if ($task->isBlockedByDependencies()) {
                    continue;
                }

                if ($dryRun) {
                    $dispatched[] = $task->ref;

                    continue;
                }

                if ($task->claim()) {
                    RunTaskJob::dispatch($task);
                    $task->recordEvent('auto_dispatched');
                    $dispatched[] = $task->ref;
                }
            }

            $plan[] = [
                'profile' => $profile->slug,
                'cap' => $cap,
                'inflight' => $inflight,
                'slots' => $slots,
                'dispatched' => $dispatched,
            ];
        }

        return $plan;
    }

    /**
     * Ready tasks for a profile in dispatch order: priority desc, oldest first.
     *
     * @return Collection<int, Task>
     */
    private function candidates(Profile $profile, int $limit): Collection
    {
        // Readiness gate: hold unscored (null) or below-threshold tasks from
        // auto-dispatch. Rather than silently excluding them, self-heal —
        // enqueue scoring for unscored tasks so a later tick can dispatch them,
        // and surface below-threshold holds via a task event. Manual dispatch
        // is unaffected. When readiness is disabled, behaviour is unchanged.
        if (config('conductor.readiness.enabled')) {
            $this->reconcileReadiness($profile);

            $min = (int) config('conductor.readiness.min_auto_dispatch', 50);

            return $profile->tasks()
                ->where('status', TaskStatus::Ready->value)
                ->whereNotNull('readiness_score')
                ->where('readiness_score', '>=', $min)
                ->orderByDesc('priority')
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($limit)
                ->get();
        }

        return $profile->tasks()
            ->where('status', TaskStatus::Ready->value)
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Self-heal the readiness state of a profile's ready tasks: enqueue
     * scoring for unscored ones and record a single held event for
     * below-threshold ones. Does not consume dispatch slots.
     */
    private function reconcileReadiness(Profile $profile): void
    {
        $min = (int) config('conductor.readiness.min_auto_dispatch', 50);

        $tasks = $profile->tasks()
            ->where('status', TaskStatus::Ready->value)
            ->where(function ($query) use ($min): void {
                $query->whereNull('readiness_score')
                    ->orWhere('readiness_score', '<', $min);
            })
            ->get();

        foreach ($tasks as $task) {
            if ($task->readiness_score === null) {
                ScoreTaskJob::dispatch($task);

                continue;
            }

            $this->recordLowScoreHold($task);
        }
    }

    /**
     * Record a held_low_score event once, not on every tick.
     */
    private function recordLowScoreHold(Task $task): void
    {
        $latest = $task->events()->first();

        if ($latest !== null && $latest->kind === 'held_low_score') {
            return;
        }

        $task->recordEvent('held_low_score', [
            'score' => $task->readiness_score,
            'min' => (int) config('conductor.readiness.min_auto_dispatch', 50),
        ]);
    }
}
