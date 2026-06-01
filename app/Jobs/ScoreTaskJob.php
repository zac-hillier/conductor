<?php

namespace App\Jobs;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\ReadinessScorer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ScoreTaskJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public Task $task) {}

    public function handle(ReadinessScorer $scorer): void
    {
        $task = $this->task->fresh();

        if ($task === null || $task->status !== TaskStatus::Ready) {
            return;
        }

        try {
            $detail = $scorer->score($task);

            $task->update([
                'readiness_score' => $detail['score'],
                'readiness_detail' => $detail,
            ]);

            $task->recordEvent('scored', [
                'score' => $detail['score'],
                'light' => $detail['light'],
                'cost' => $detail['cost'] ?? null,
            ]);
        } catch (Throwable $e) {
            $task->recordEvent('score_failed', [
                'reason' => mb_substr($e->getMessage(), 0, 280),
            ]);
        }
    }
}
