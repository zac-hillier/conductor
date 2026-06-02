<?php

namespace App\Jobs;

use App\Enums\PhaseStatus;
use App\Enums\PlanStatus;
use App\Enums\TaskStatus;
use App\Models\Phase;
use App\Services\Claude\ClaudeRunner;
use App\Services\PlanCoordinator;
use App\Support\JsonExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Automated post-phase review gate (ag-review). A red verdict blocks the plan;
 * anything else advances it. Only runs when conductor.pipeline.review.enabled.
 */
class ReviewPhaseJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public Phase $phase) {}

    public function handle(ClaudeRunner $runner, PlanCoordinator $coordinator): void
    {
        $phase = $this->phase->fresh();

        if ($phase === null || $phase->status !== PhaseStatus::Done) {
            return;
        }

        $plan = $phase->plan;
        $workdir = $plan->project->resolvedWorkdir();

        if (empty($workdir) || ! is_dir($workdir)) {
            return;
        }

        try {
            $result = $runner->run($this->prompt($phase), $workdir, [
                'agent' => (string) config('conductor.pipeline.review.agent'),
                'allowed_tools' => ['Read', 'Grep', 'Glob'],
                'timeout' => (int) config('conductor.pipeline.review.timeout'),
            ]);

            $parsed = $result->isError ? null : JsonExtractor::firstObject($result->result);
            $verdict = strtolower((string) ($parsed['verdict'] ?? 'green'));
        } catch (Throwable) {
            $verdict = 'green'; // a failed review must not silently block; fall through.
        }

        $phase->update(['review_verdict' => $verdict]);

        if ($verdict === 'red') {
            $phase->update(['status' => PhaseStatus::Blocked]);
            $plan->update(['status' => PlanStatus::Blocked]);

            if ($phase->task !== null) {
                $phase->task->update(['status' => TaskStatus::Blocked]);
                $phase->task->recordEvent('phase_review_blocked', ['phase' => $phase->number]);
            }

            return;
        }

        $coordinator->advance($plan);
    }

    private function prompt(Phase $phase): string
    {
        $artifactDir = $phase->plan->artifact_dir;

        return implode("\n", [
            'Review the just-completed Phase '.$phase->number.' ('.$phase->name.') of a multi-phase build.',
            'You have read-only access (Read, Grep, Glob). Do not modify anything.',
            $artifactDir ? 'Plan + summary: '.$artifactDir.'/planning/.' : '',
            'Objective: '.($phase->objective ?? '(see plan)'),
            'Exit criteria: '.implode('; ', $phase->exit_criteria ?? []),
            '',
            'Assess whether the phase is genuinely done and safe to build on. Respond with ONLY a single',
            'JSON object: {"verdict": "green"|"amber"|"red", "blockers": string[], "summary": string}.',
            'Use red ONLY for blocking (P0) problems — missing functionality, broken core paths, failed gates.',
        ]);
    }
}
