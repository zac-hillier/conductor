<?php

namespace App\Jobs;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Services\Claude\ClaudeRunner;
use App\Services\PlanPromptBuilder;
use App\Support\JsonExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Drives ag-phase (optionally ag-scout first) to decompose a plan's concept
 * into phases. The planning agent writes the cc/<date>-<slug>/planning/ tree
 * AND appends a JSON phases manifest, which Conductor parses into phase rows.
 */
class GeneratePlanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public Plan $plan) {}

    public function handle(ClaudeRunner $runner, PlanPromptBuilder $prompts): void
    {
        $plan = $this->plan->fresh();

        if ($plan === null || $plan->status !== PlanStatus::Drafting) {
            return;
        }

        $workdir = $plan->project->resolvedWorkdir();

        if (empty($workdir) || ! is_dir($workdir)) {
            $plan->update(['status' => PlanStatus::Blocked]);

            return;
        }

        $artifactDir = rtrim($workdir, '/').'/cc/'.now()->format('Y-m-d').'-'.$plan->slug;
        $plan->update(['artifact_dir' => $artifactDir]);

        $cost = 0.0;

        try {
            if ((bool) config('conductor.pipeline.scout.enabled')) {
                $plan->update(['status' => PlanStatus::Scouting]);

                $scout = $runner->run($prompts->scout($plan, $artifactDir), $workdir, [
                    'agent' => (string) config('conductor.pipeline.scout.agent'),
                    'allowed_tools' => ['Read', 'Grep', 'Glob', 'WebFetch', 'WebSearch'],
                    'timeout' => (int) config('conductor.pipeline.scout.timeout'),
                ]);

                $cost += (float) ($scout->costUsd ?? 0);
            }

            $plan->update(['status' => PlanStatus::Planning]);

            $result = $runner->run($prompts->phase($plan, $artifactDir), $workdir, [
                'agent' => (string) config('conductor.pipeline.phase.agent'),
                'model' => (string) config('conductor.pipeline.phase.model'),
                'timeout' => (int) config('conductor.pipeline.phase.timeout'),
            ]);

            $cost += (float) ($result->costUsd ?? 0);

            $manifest = $result->isError ? null : JsonExtractor::firstObject($result->result);
            $phases = is_array($manifest['phases'] ?? null) ? $manifest['phases'] : [];

            if ($phases === []) {
                $plan->update(['status' => PlanStatus::Blocked, 'cost' => $cost ?: null]);

                return;
            }

            foreach (array_values($phases) as $i => $phase) {
                if (! is_array($phase)) {
                    continue;
                }

                $plan->phases()->create([
                    'number' => $i + 1,
                    'name' => $this->str($phase['name'] ?? null) ?? ('Phase '.($i + 1)),
                    'objective' => $this->str($phase['objective'] ?? null),
                    'gateway_test' => $this->str($phase['gateway_test'] ?? null),
                    'exit_criteria' => $this->stringList($phase['exit_criteria'] ?? []),
                ]);
            }

            $plan->update([
                'status' => PlanStatus::Ready,
                'cost' => $cost ?: null,
                'summary' => $this->str($manifest['summary'] ?? null),
            ]);
        } catch (Throwable) {
            $plan->update(['status' => PlanStatus::Blocked, 'cost' => $cost ?: null]);
        }
    }

    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        return $items;
    }
}
