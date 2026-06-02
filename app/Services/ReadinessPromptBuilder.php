<?php

namespace App\Services;

use App\Models\Task;

class ReadinessPromptBuilder
{
    /**
     * Per-reviewer framing: the lens each specialist applies to the brief.
     */
    private const LENSES = [
        'ag-review' => 'Assess the clarity and quality of the brief and its definition of done. '
            .'Is the work well specified, unambiguous, and verifiable? Could an autonomous worker '
            .'tell objectively when it is done?',
        'ag-gap-analysis' => 'Hunt for missing context, undefined terms, unstated assumptions, and '
            .'spec gaps. What does the brief leave implicit that a worker would have to guess at?',
        'ag-devops-review' => 'Assess executability and blast radius. Do the target paths exist and '
            .'are they reachable in this working directory? What is the environmental risk of letting '
            .'an autonomous worker act on this brief?',
    ];

    public function __construct(private readonly ProjectContextBuilder $context = new ProjectContextBuilder) {}

    public function for(Task $task, string $reviewerAgent): string
    {
        $lines = [];

        $lines[] = 'You are assessing whether a development task is ready to hand to an autonomous worker.';
        $lines[] = 'You have read-only access (Read, Grep, Glob) to the working directory so you can';
        $lines[] = 'inspect the repository for context. Do not attempt to modify anything.';

        $lens = self::LENSES[$reviewerAgent] ?? 'Assess the task readiness for autonomous execution from your area of expertise.';
        $lines[] = '';
        $lines[] = 'Your lens for this assessment:';
        $lines[] = $lens;

        $context = $this->context->block($task);
        if ($context !== []) {
            $lines[] = '';
            $lines = array_merge($lines, $context);
        }

        $lines[] = '';
        $lines[] = 'Task brief:';
        $lines[] = '- Title: '.$task->title;

        if (! empty($task->summary)) {
            $lines[] = '- Summary: '.$task->summary;
        }

        $dod = $task->definition_of_done ?? [];
        if ($dod !== []) {
            $lines[] = '- Definition of done:';
            foreach ($dod as $item) {
                $lines[] = '  - '.$item;
            }
        }

        $constraints = $task->constraints ?? [];
        if ($constraints !== []) {
            $lines[] = '- Constraints:';
            foreach ($constraints as $item) {
                $lines[] = '  - '.$item;
            }
        }

        $targets = $task->target_paths ?? [];
        if ($targets !== []) {
            $lines[] = '- Target paths:';
            foreach ($targets as $item) {
                $lines[] = '  - '.$item;
            }
        }

        $lines[] = '';
        $lines[] = 'Score, from your lens, how likely an autonomous worker is to succeed at this task';
        $lines[] = 'as specified, on a scale of 0 (certain to fail or get stuck) to 100 (certain to succeed).';
        $lines[] = '';
        $lines[] = 'Respond with ONLY a single JSON object, no prose around it, of this exact shape:';
        $lines[] = '{"score": 0-100, "summary": string, "blockers": string[]}';

        return implode("\n", $lines);
    }
}
