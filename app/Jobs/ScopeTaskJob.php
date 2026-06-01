<?php

namespace App\Jobs;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskComment;
use App\Services\Claude\ClaudeRunner;
use App\Services\Claude\ProcessClaudeRunner;
use App\Services\ScopePromptBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ScopeTaskJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Read-only tools available to a scoping session.
     */
    public const ALLOWED_TOOLS = ['Read', 'Grep', 'Glob'];

    private const SUMMARY_LIMIT = 10000;

    public function __construct(public Task $task) {}

    public function handle(ClaudeRunner $runner, ScopePromptBuilder $promptBuilder): void
    {
        $task = $this->task->fresh();

        if ($task === null || $task->status !== TaskStatus::Scoping) {
            return;
        }

        $run = $task->runs()->create([
            'attempt' => $task->nextAttempt(),
            'kind' => 'scope',
            'started_at' => now(),
        ]);

        $task->recordEvent('scoping_started', ['attempt' => $run->attempt]);

        try {
            $prompt = $promptBuilder->build($task);
            $workdir = $task->profile->workdir ?? base_path();

            $result = $runner->run($prompt, $workdir, [
                'allowed_tools' => self::ALLOWED_TOOLS,
            ]);

            $logRef = $this->writeLog($run->id, $result->rawJson);

            $sessionPath = $result->sessionId !== null
                ? ProcessClaudeRunner::transcriptPath($workdir, $result->sessionId)
                : null;

            $run->update([
                'finished_at' => now(),
                'outcome' => $result->isError ? 'failed' : 'success',
                'summary' => $this->truncate($result->result),
                'token_count' => $result->totalTokens(),
                'cost' => $result->costUsd,
                'session_id' => $result->sessionId,
                'claude_session_path' => $sessionPath,
                'log_ref' => $logRef,
            ]);

            if ($result->isError) {
                $this->fail($task, $result->result);

                return;
            }

            $parsed = $this->extractJson($result->result);

            if ($parsed === null) {
                $this->fail($task, $result->result);

                return;
            }

            if ($this->boolish($parsed['ready'] ?? false)) {
                $this->markReady($task, $parsed);

                return;
            }

            $this->askQuestions($task, $this->stringList($parsed['questions'] ?? []));
        } catch (Throwable $e) {
            $run->update([
                'finished_at' => now(),
                'outcome' => 'failed',
                'summary' => $this->truncate($e->getMessage()),
            ]);

            $this->fail($task, $e->getMessage());
        }
    }

    /**
     * @param  array<int, string>  $questions
     */
    private function askQuestions(Task $task, array $questions): void
    {
        $body = $questions !== []
            ? "I need some clarification before this is ready:\n\n".implode("\n", array_map(
                fn (string $q) => '- '.$q,
                $questions,
            ))
            : 'I need more detail before this task is ready, but no specific questions were returned.';

        $task->comments()->create([
            'author' => TaskComment::AUTHOR_AGENT,
            'body' => $body,
        ]);

        $this->transition($task, TaskStatus::NeedsInput);
        $task->recordEvent('scope_questions', ['count' => count($questions)]);
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function markReady(Task $task, array $parsed): void
    {
        $updates = [];

        $title = $this->nonEmptyString($parsed['title'] ?? null);
        if ($title !== null) {
            $updates['title'] = $title;
        }

        $summary = $this->nonEmptyString($parsed['summary'] ?? null);
        if ($summary !== null) {
            $updates['summary'] = $summary;
        }

        $dod = $this->stringList($parsed['definition_of_done'] ?? []);
        if ($dod !== []) {
            $updates['definition_of_done'] = $dod;
        }

        $constraints = $this->stringList($parsed['constraints'] ?? []);
        if ($constraints !== []) {
            $updates['constraints'] = $constraints;
        }

        $targets = $this->stringList($parsed['target_paths'] ?? []);
        if ($targets !== []) {
            $updates['target_paths'] = $targets;
        }

        if ($updates !== []) {
            $task->update($updates);
        }

        $this->transition($task, TaskStatus::Ready);
        $task->recordEvent('scoped_ready');
    }

    private function fail(Task $task, string $raw): void
    {
        $task->comments()->create([
            'author' => TaskComment::AUTHOR_AGENT,
            'body' => "Scoping could not be completed automatically. Raw response:\n\n".$this->truncate($raw, 4000),
        ]);

        $this->transition($task, TaskStatus::NeedsInput);
        $task->recordEvent('scope_failed');
    }

    private function transition(Task $task, TaskStatus $to): void
    {
        $from = $task->status;

        if ($from === $to) {
            return;
        }

        $task->update(['status' => $to]);
        $task->recordEvent('status_changed', [
            'from' => $from->value,
            'to' => $to->value,
        ]);
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

    private function boolish(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['true', '1', 'yes'], true);
        }

        return (bool) $value;
    }

    private function nonEmptyString(mixed $value): ?string
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
            $string = $this->nonEmptyString(is_scalar($item) ? (string) $item : null);

            if ($string !== null) {
                $items[] = $string;
            }
        }

        return $items;
    }

    private function writeLog(int $runId, string $contents): string
    {
        $path = "conductor/runs/{$runId}.json";
        Storage::disk('local')->put($path, $contents);

        return $path;
    }

    private function truncate(string $text, int $limit = self::SUMMARY_LIMIT): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit).'…';
    }
}
