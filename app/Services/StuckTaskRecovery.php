<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskRun;
use Illuminate\Support\Carbon;

class StuckTaskRecovery
{
    /**
     * Characters of transcript tail retained in the recovery comment.
     */
    private const SNIPPET_LIMIT = 1000;

    /**
     * Recover tasks stuck in processing whose worker run never finished.
     *
     * A task is considered stuck when it is in processing, its latest
     * execute run has no finished_at, and that run started longer ago than
     * the configured timeout plus a grace period.
     *
     * @return array<int, array{task_id: int, ref: ?string, run_id: int, started_at: ?string}>
     */
    public function recover(bool $dryRun = false): array
    {
        $cutoff = $this->cutoff();
        $recovered = [];

        Task::query()
            ->where('status', TaskStatus::Processing->value)
            ->each(function (Task $task) use ($cutoff, $dryRun, &$recovered): void {
                $run = $task->runs()
                    ->where('kind', 'execute')
                    ->orderByDesc('id')
                    ->first();

                if ($run === null
                    || $run->finished_at !== null
                    || $run->started_at === null
                    || $run->started_at->greaterThan($cutoff)) {
                    return;
                }

                $summary = [
                    'task_id' => $task->id,
                    'ref' => $task->ref,
                    'run_id' => $run->id,
                    'started_at' => optional($run->started_at)->toIso8601String(),
                ];

                if ($dryRun) {
                    $recovered[] = $summary;

                    return;
                }

                $this->recoverTask($task, $run);

                $recovered[] = $summary;
            });

        return $recovered;
    }

    private function recoverTask(Task $task, TaskRun $run): void
    {
        $now = now();
        $snippet = $this->transcriptSnippet($run->claude_session_path);

        $run->update([
            'outcome' => 'failed',
            'finished_at' => $now,
        ]);

        $task->update(['status' => TaskStatus::Blocked]);
        $task->recordEvent('status_changed', [
            'from' => TaskStatus::Processing->value,
            'to' => TaskStatus::Blocked->value,
        ]);
        $task->recordEvent('recovered', [
            'run_id' => $run->id,
            'started_at' => optional($run->started_at)->toIso8601String(),
        ]);

        $body = "The worker did not finish and was recovered automatically "
            ."(likely crashed or was killed).\n\n"
            .'Recovered at: '.$now->toDateTimeString()."\n";

        if ($run->started_at !== null) {
            $body .= 'Run started: '.$run->started_at->toDateTimeString()."\n";
        }

        if ($snippet !== null) {
            $body .= "\nLast recorded activity:\n```\n".$snippet."\n```\n";
        }

        $body .= "\nPlease review and retry this task.";

        $task->comments()->create([
            'author' => TaskComment::AUTHOR_AGENT,
            'body' => $body,
        ]);
    }

    private function transcriptSnippet(?string $path): ?string
    {
        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false || trim($contents) === '') {
            return null;
        }

        $tail = mb_substr(rtrim($contents), -self::SNIPPET_LIMIT);

        return trim($tail);
    }

    private function cutoff(): Carbon
    {
        $timeout = (int) config('conductor.claude.timeout', 600);
        $grace = (int) config('conductor.recovery.grace', 120);

        return now()->subSeconds($timeout + $grace);
    }
}
