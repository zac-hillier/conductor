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

        // Never run a worker without a valid project home — an empty workdir
        // would execute in Conductor's own directory. Block with a clear
        // explanation rather than running in the wrong place.
        if (! $task->hasValidWorkdir()) {
            $task->update(['status' => TaskStatus::Blocked]);
            $task->recordEvent('status_changed', [
                'from' => TaskStatus::Processing->value,
                'to' => TaskStatus::Blocked->value,
            ]);
            $task->recordEvent('workdir_invalid', [
                'workdir' => $task->resolvedWorkdir(),
            ]);

            Notifier::taskAttention($task->fresh(), 'blocked');

            return;
        }

        $run = $task->runs()->create([
            'attempt' => $task->nextAttempt(),
            'started_at' => now(),
        ]);

        $task->recordEvent('dispatched', ['attempt' => $run->attempt]);

        try {
            $prompt = $promptBuilder->build($task);
            $workdir = (string) $task->resolvedWorkdir();
            $policy = $task->profile->policyOrDefault();

            $disallowed = array_values(array_diff(
                array_merge($policy->disallowedTools(), $task->referenceGuards()),
                $task->grantedCapabilities(),
            ));

            $result = $runner->run($prompt, $workdir, [
                'permission_mode' => $policy->permissionMode(),
                'disallowed_tools' => $disallowed,
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

            $requests = $this->parseApprovalRequests($result->result);

            if ($requests !== []) {
                foreach ($requests as $request) {
                    $task->approvals()->create([
                        'run_id' => $run->id,
                        'capability' => $request['capability'],
                        'command' => $request['command'],
                        'reason' => $request['reason'],
                        'decision' => 'pending',
                    ]);
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
                $task->recordEvent('approvals_requested', ['count' => count($requests)]);

                Notifier::taskAttention($task->fresh(), 'review');

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

    /**
     * Parse an APPROVALS_REQUESTED block from worker output. The header is
     * matched case-insensitively; the following `- a :: b :: c` lines are
     * collected until a blank line or end of input.
     *
     * @return array<int, array{capability: string, command: ?string, reason: ?string}>
     */
    private function parseApprovalRequests(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $requests = [];
        $inBlock = false;

        foreach ($lines as $line) {
            if (! $inBlock) {
                if (preg_match('/^\s*APPROVALS_REQUESTED:\s*$/i', $line) === 1) {
                    $inBlock = true;
                }

                continue;
            }

            $trimmed = trim($line);

            if ($trimmed === '') {
                break;
            }

            if (! str_starts_with($trimmed, '-')) {
                break;
            }

            $body = trim(ltrim($trimmed, '-'));
            $parts = array_map('trim', explode('::', $body));

            $capability = $parts[0] ?? '';
            if ($capability === '') {
                continue;
            }

            $requests[] = [
                'capability' => $capability,
                'command' => ($parts[1] ?? '') !== '' ? $parts[1] : null,
                'reason' => ($parts[2] ?? '') !== '' ? $parts[2] : null,
            ];
        }

        return $requests;
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
