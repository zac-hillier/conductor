<?php

namespace App\Jobs;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\Claude\ClaudeRunner;
use App\Services\Claude\ProcessClaudeRunner;
use App\Services\Notifier;
use App\Services\TaskPromptBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RunTaskJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum characters of result text retained on the run record.
     */
    private const SUMMARY_LIMIT = 10000;

    /**
     * Belt-and-braces guard against a duplicate job for the same task being
     * queued while one is already pending. The atomic claim is the primary
     * defence; this simply prevents redundant queue entries.
     */
    public int $uniqueFor = 3600;

    public function __construct(public Task $task) {}

    public function uniqueId(): string
    {
        return (string) $this->task->id;
    }

    public function handle(ClaudeRunner $runner, TaskPromptBuilder $promptBuilder): void
    {
        $task = $this->task->fresh();

        if ($task === null) {
            return;
        }

        // A ready task may not yet have been claimed (e.g. dispatchSync or a
        // direct retry). Claiming here is idempotent: it transitions ready ->
        // processing and records the event, or no-ops if already processing.
        if ($task->status === TaskStatus::Ready) {
            $task->claim();
        }

        // A blocked task is being retried directly; promote it to processing so
        // the run proceeds from a consistent state.
        if ($task->status === TaskStatus::Blocked) {
            $task->update(['status' => TaskStatus::Processing]);
            $task->recordEvent('status_changed', [
                'from' => TaskStatus::Blocked->value,
                'to' => TaskStatus::Processing->value,
            ]);
        }

        if ($task->status !== TaskStatus::Processing) {
            return;
        }

        $run = $task->runs()->create([
            'attempt' => $task->nextAttempt(),
            'started_at' => now(),
        ]);

        $task->recordEvent('dispatched', ['attempt' => $run->attempt]);

        try {
            $prompt = $promptBuilder->build($task);
            $workdir = $task->profile->workdir ?? base_path();
            $policy = $task->profile->policyOrDefault();

            $result = $runner->run($prompt, $workdir, [
                'permission_mode' => $policy->permissionMode(),
                'disallowed_tools' => $policy->disallowedTools(),
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
                $task->update(['status' => TaskStatus::Blocked]);
                $task->recordEvent('status_changed', [
                    'from' => TaskStatus::Processing->value,
                    'to' => TaskStatus::Blocked->value,
                ]);
                $task->recordEvent('run_failed', [
                    'attempt' => $run->attempt,
                    'reason' => $this->truncate($result->result, 280),
                ]);

                Notifier::taskAttention($task->fresh(), 'run_failed');

                return;
            }

            $destination = $policy->requiresReview()
                ? TaskStatus::Review
                : TaskStatus::Complete;

            $task->update(['status' => $destination]);
            $task->recordEvent('status_changed', [
                'from' => TaskStatus::Processing->value,
                'to' => $destination->value,
            ]);
            $task->recordEvent('run_completed', [
                'attempt' => $run->attempt,
                'cost' => $result->costUsd,
                'tokens' => $result->totalTokens(),
                'session_id' => $result->sessionId,
            ]);
            $task->recordEvent(
                $destination === TaskStatus::Review ? 'awaiting_review' : 'completed',
                ['attempt' => $run->attempt],
            );

            Notifier::taskAttention(
                $task->fresh(),
                $destination === TaskStatus::Review ? 'review' : 'complete',
            );
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

            Notifier::taskAttention($task->fresh(), 'run_failed');
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
