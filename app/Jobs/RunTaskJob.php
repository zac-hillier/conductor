<?php

namespace App\Jobs;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskRun;
use App\Services\Claude\ClaudeRunner;
use App\Services\Claude\ProcessClaudeRunner;
use App\Services\TaskPromptBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RunTaskJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum characters of result text retained on the run record.
     */
    private const SUMMARY_LIMIT = 10000;

    public function __construct(public Task $task) {}

    public function handle(ClaudeRunner $runner, TaskPromptBuilder $promptBuilder): void
    {
        $task = $this->task->fresh();

        if ($task === null) {
            return;
        }

        if (! in_array($task->status, [TaskStatus::Ready, TaskStatus::Blocked], true)) {
            return;
        }

        $from = $task->status;
        $task->update(['status' => TaskStatus::Processing]);
        $task->recordEvent('status_changed', [
            'from' => $from->value,
            'to' => TaskStatus::Processing->value,
        ]);

        $run = $task->runs()->create([
            'attempt' => $task->nextAttempt(),
            'started_at' => now(),
        ]);

        $task->recordEvent('dispatched', ['attempt' => $run->attempt]);

        try {
            $prompt = $promptBuilder->build($task);
            $workdir = $task->profile->workdir ?? base_path();

            $result = $runner->run($prompt, $workdir);

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
                $task->update(['status' => TaskStatus::Blocked]);
                $task->recordEvent('status_changed', [
                    'from' => TaskStatus::Processing->value,
                    'to' => TaskStatus::Blocked->value,
                ]);
                $task->recordEvent('run_failed', [
                    'attempt' => $run->attempt,
                    'reason' => $this->truncate($result->result, 280),
                ]);

                return;
            }

            $task->update(['status' => TaskStatus::Review]);
            $task->recordEvent('status_changed', [
                'from' => TaskStatus::Processing->value,
                'to' => TaskStatus::Review->value,
            ]);
            $task->recordEvent('run_completed', [
                'attempt' => $run->attempt,
                'cost' => $result->costUsd,
                'tokens' => $result->totalTokens(),
                'session_id' => $result->sessionId,
            ]);
        } catch (Throwable $e) {
            $run->update([
                'finished_at' => now(),
                'outcome' => 'failed',
                'summary' => $this->truncate($e->getMessage()),
            ]);

            $task->update(['status' => TaskStatus::Blocked]);
            $task->recordEvent('status_changed', [
                'from' => TaskStatus::Processing->value,
                'to' => TaskStatus::Blocked->value,
            ]);
            $task->recordEvent('run_failed', [
                'attempt' => $run->attempt,
                'reason' => $this->truncate($e->getMessage(), 280),
            ]);
        }
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
