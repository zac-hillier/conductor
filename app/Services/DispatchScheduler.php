<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Jobs\RunTaskJob;
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
        $query = $profile->tasks()
            ->where('status', TaskStatus::Ready->value);

        // Readiness gate: hold unscored (null) or below-threshold tasks from
        // auto-dispatch. They remain ready and visible, awaiting scoring or a
        // human decision. Manual dispatch is unaffected. When readiness is
        // disabled, behaviour is unchanged.
        if (config('conductor.readiness.enabled')) {
            $query->whereNotNull('readiness_score')
                ->where('readiness_score', '>=', (int) config('conductor.readiness.min_auto_dispatch', 50));
        }

        return $query
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }
}
