<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Task;

/**
 * Builds the scout and phase-planning prompts for the orchestration pipeline.
 * Reuses ProjectContextBuilder via a transient (unsaved) Task so the planning
 * agents get the same curated project context as workers do.
 */
class PlanPromptBuilder
{
    public function __construct(private readonly ProjectContextBuilder $context = new ProjectContextBuilder) {}

    public function scout(Plan $plan, string $artifactDir): string
    {
        $lines = [];
        $lines[] = 'Research the codebase and this build to produce a concise scout report that a';
        $lines[] = 'planning agent can use. You have read-only access; do not modify code.';

        $lines = array_merge($lines, $this->context->block($this->transientTask($plan)));

        $lines[] = '';
        $lines[] = 'Build concept:';
        $lines[] = $plan->concept ?: $plan->name;
        $lines[] = '';
        $lines[] = 'Write your scout report under '.$artifactDir.'/research/.';

        return implode("\n", $lines);
    }

    public function phase(Plan $plan, string $artifactDir): string
    {
        $lines = [];
        $lines[] = 'Produce a horizontal-layered, phase-based implementation plan for the build below.';

        $lines = array_merge($lines, $this->context->block($this->transientTask($plan)));

        $lines[] = '';
        $lines[] = 'Build concept:';
        $lines[] = $plan->concept ?: $plan->name;
        $lines[] = '';
        $lines[] = 'Write the full plan to '.$artifactDir.'/planning/phase-plan.md and create a living';
        $lines[] = $artifactDir.'/planning/phase-summary.md alongside it. Give each phase an objective,';
        $lines[] = 'a gateway test, and measurable exit criteria.';
        $lines[] = '';
        $lines[] = 'THEN, as the very last thing in your response, output ONLY a single JSON object (no';
        $lines[] = 'prose around it) summarising the phases, of this exact shape:';
        $lines[] = '{"summary": string, "phases": [{"number": int, "name": string, "objective": string,';
        $lines[] = ' "gateway_test": string|null, "exit_criteria": string[]}]}';

        return implode("\n", $lines);
    }

    private function transientTask(Plan $plan): Task
    {
        $task = new Task([
            'title' => $plan->name,
            'summary' => $plan->concept,
        ]);

        $task->setRelation('project', $plan->project);
        $task->setRelation('profile', $plan->project->profile);

        return $task;
    }
}
