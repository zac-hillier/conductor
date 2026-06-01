<?php

namespace App\Services;

use App\Models\Task;
use App\Services\Claude\ClaudeRunner;
use Throwable;

class ReadinessScorer
{
    /**
     * Read-only tools available to a scoring session.
     */
    public const ALLOWED_TOOLS = ['Read', 'Grep', 'Glob'];

    public function __construct(
        private ClaudeRunner $runner,
        private ReadinessPromptBuilder $promptBuilder,
    ) {}

    /**
     * Run every configured reviewer over the task and aggregate the result.
     *
     * @return array{
     *     light: string,
     *     score: int|null,
     *     reviewers: array<int, array<string, mixed>>,
     *     scored_at: string,
     *     cost: float|null,
     * }
     */
    public function score(Task $task): array
    {
        $workdir = $task->profile->workdir ?? base_path();

        /** @var array<int, string> $reviewerAgents */
        $reviewerAgents = (array) config('conductor.readiness.reviewers', []);

        $reviewers = [];
        $scores = [];
        $cost = null;

        foreach ($reviewerAgents as $agent) {
            $reviewers[] = $this->runReviewer($task, $workdir, (string) $agent, $scores, $cost);
        }

        $score = $scores !== []
            ? (int) round(array_sum($scores) / count($scores))
            : null;

        return [
            'light' => $this->light($score),
            'score' => $score,
            'reviewers' => $reviewers,
            'scored_at' => now()->toIso8601String(),
            'cost' => $cost,
        ];
    }

    /**
     * @param  array<int, int>  $scores  collected scores (mutated)
     * @return array<string, mixed>
     */
    private function runReviewer(Task $task, string $workdir, string $agent, array &$scores, ?float &$cost): array
    {
        try {
            $prompt = $this->promptBuilder->for($task, $agent);

            $result = $this->runner->run($prompt, $workdir, [
                'agent' => $agent,
                'allowed_tools' => self::ALLOWED_TOOLS,
            ]);

            if ($result->costUsd !== null) {
                $cost = (float) $cost + $result->costUsd;
            }

            if ($result->isError) {
                return ['agent' => $agent, 'score' => null, 'summary' => null, 'blockers' => [], 'error' => $result->result];
            }

            $parsed = $this->extractJson($result->result);

            if ($parsed === null || ! isset($parsed['score']) || ! is_numeric($parsed['score'])) {
                return ['agent' => $agent, 'score' => null, 'summary' => null, 'blockers' => [], 'error' => 'Could not parse a score from the reviewer response.'];
            }

            $value = max(0, min(100, (int) round((float) $parsed['score'])));
            $scores[] = $value;

            return [
                'agent' => $agent,
                'score' => $value,
                'summary' => isset($parsed['summary']) && is_scalar($parsed['summary']) ? (string) $parsed['summary'] : null,
                'blockers' => $this->stringList($parsed['blockers'] ?? []),
            ];
        } catch (Throwable $e) {
            return ['agent' => $agent, 'score' => null, 'summary' => null, 'blockers' => [], 'error' => $e->getMessage()];
        }
    }

    private function light(?int $score): string
    {
        if ($score === null) {
            return 'red';
        }

        if ($score >= (int) config('conductor.readiness.green', 80)) {
            return 'green';
        }

        if ($score >= (int) config('conductor.readiness.amber', 50)) {
            return 'amber';
        }

        return 'red';
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
            if (! is_scalar($item)) {
                continue;
            }

            $string = trim((string) $item);

            if ($string !== '') {
                $items[] = $string;
            }
        }

        return $items;
    }

    /**
     * Extract the first balanced JSON object from arbitrary text.
     *
     * @return array<string, mixed>|null
     */
    private function extractJson(string $text): ?array
    {
        $start = strpos($text, '{');

        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($text);

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    $candidate = substr($text, $start, $i - $start + 1);
                    $decoded = json_decode($candidate, true);

                    return is_array($decoded) ? $decoded : null;
                }
            }
        }

        return null;
    }
}
