<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;

class TaskAttentionNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Task $task,
        public string $reason,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $task = $this->task;
        $profile = $task->profile;

        $ref = $task->ref ?? '#'.$task->id;
        $heading = sprintf('%s — %s', $profile->name, $this->reasonLabel());

        $lines = [
            sprintf('*%s* %s', $ref, $task->title),
            sprintf('Status: *%s*', $task->status->label()),
        ];

        if ($task->readiness_score !== null) {
            $light = is_array($task->readiness_detail) ? ($task->readiness_detail['light'] ?? null) : null;

            $lines[] = $light !== null
                ? sprintf('Readiness: %d (%s)', $task->readiness_score, $light)
                : sprintf('Readiness: %d', $task->readiness_score);
        }

        $url = route('profiles.board', $profile).'?task='.($task->ref ?? $task->id);
        $lines[] = sprintf('<%s|Open on the board>', $url);

        return (new SlackMessage)
            ->text($heading)
            ->headerBlock($heading)
            ->sectionBlock(function ($block) use ($lines): void {
                $block->text(implode("\n", $lines))->markdown();
            });
    }

    private function reasonLabel(): string
    {
        return match ($this->reason) {
            'needs_input' => 'Needs input',
            'blocked' => 'Blocked',
            'review' => 'Awaiting review',
            'complete' => 'Complete',
            'run_failed' => 'Run failed',
            'scope_failed' => 'Scoping failed',
            default => ucfirst(str_replace('_', ' ', $this->reason)),
        };
    }
}
