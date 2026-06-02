<?php

namespace App\Services;

use App\Models\Task;

/**
 * Builds the execution prompt for a phase-backed task. The worker runs as
 * ag-exec (set by RunTaskJob), so this prompt supplies the phase objective,
 * pointers to the plan artifacts, the goal-contract instruction, and the
 * curated project context.
 */
class PhasePromptBuilder
{
    public function __construct(private readonly ProjectContextBuilder $context = new ProjectContextBuilder) {}

    public function build(Task $task): string
    {
        $phase = $task->phase;
        $plan = $phase?->plan;
        $artifactDir = $plan?->artifact_dir;

        $lines = [];
        $lines[] = 'You are executing one phase of a multi-phase build in the current working directory.';

        $context = $this->context->block($task);
        if ($context !== []) {
            $lines[] = '';
            $lines = array_merge($lines, $context);
        }

        if ($phase !== null) {
            $lines[] = '';
            $lines[] = 'Phase '.$phase->number.': '.$phase->name;

            if (! empty($phase->objective)) {
                $lines[] = 'Objective: '.$phase->objective;
            }

            if (! empty($phase->gateway_test)) {
                $lines[] = 'Gateway test (must pass before building): '.$phase->gateway_test;
            }

            $exit = $phase->exit_criteria ?? [];
            if ($exit !== []) {
                $lines[] = 'Exit criteria (all must hold):';
                foreach ($exit as $item) {
                    $lines[] = '- '.$item;
                }
            }
        }

        if (! empty($artifactDir)) {
            $lines[] = '';
            $lines[] = 'Plan artifacts:';
            $lines[] = '- Read your phase\'s section of '.$artifactDir.'/planning/phase-plan.md.';
            $lines[] = '- Read '.$artifactDir.'/planning/phase-summary.md for prior-phase context, and UPDATE it when this phase is done.';
        }

        $lines[] = '';
        $lines[] = 'Use the goal-contract skill (~/.claude/skills/goal_contract.md): create/maintain';
        $lines[] = './goal-contract.md, classify every work unit, and treat the phase as done ONLY when';
        $lines[] = 'its verification gate passes.';
        $lines[] = '';
        $lines[] = 'Before finishing, maintain the project living docs (cc/agent_docs/ if present, else';
        $lines[] = 'create it): append a dated CHANGELOG.md entry for this phase, tick this phase in';
        $lines[] = 'ROADMAP.md, and open/close BLOCKERS.md entries as needed. Do NOT edit anything under a';
        $lines[] = 'reference/ directory. When finished, give a concise summary of what you changed.';

        return implode("\n", $lines);
    }
}
