<?php

namespace App\Services;

use App\Models\Task;
use App\Notifications\TaskAttentionNotification;
use Illuminate\Support\Facades\Notification;

class Notifier
{
    /**
     * Send a Slack notification for a task that needs human attention.
     *
     * No-ops when notifications are disabled or the reason is not in the
     * configured allow-list.
     */
    public static function taskAttention(Task $task, string $reason): void
    {
        if (! config('conductor.notifications.enabled')) {
            return;
        }

        $events = (array) config('conductor.notifications.events', []);

        if (! in_array($reason, $events, true)) {
            return;
        }

        $channel = config('services.slack.notifications.default_channel');

        Notification::route('slack', $channel)
            ->notify(new TaskAttentionNotification($task, $reason));
    }
}
