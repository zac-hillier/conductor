<?php

use App\Models\Profile;
use App\Models\Task;
use App\Notifications\TaskAttentionNotification;
use App\Services\Notifier;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

it('sends a slack notification for an allow-listed reason', function (string $reason) {
    Notification::fake();

    $profile = Profile::factory()->customer()->create(['name' => 'Acme Ltd']);
    $task = Task::factory()->for($profile)->create();

    Notifier::taskAttention($task, $reason);

    Notification::assertSentOnDemand(
        TaskAttentionNotification::class,
        function (TaskAttentionNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($reason) {
            return $notification->reason === $reason
                && in_array('slack', $channels, true)
                && $notifiable->routeNotificationFor('slack') === config('services.slack.notifications.default_channel');
        },
    );
})->with(['needs_input', 'blocked', 'review', 'complete', 'run_failed']);

it('does not send when notifications are disabled', function () {
    Notification::fake();
    config(['conductor.notifications.enabled' => false]);

    $task = Task::factory()->create();

    Notifier::taskAttention($task, 'review');

    Notification::assertNothingSent();
});

it('does not send for a reason outside the events allow-list', function () {
    Notification::fake();

    $task = Task::factory()->create();

    Notifier::taskAttention($task, 'scored');

    Notification::assertNothingSent();
});

it('renders ref, title, status, profile and a board link in the slack message', function () {
    $profile = Profile::factory()->customer()->create(['name' => 'Acme Ltd', 'slug' => 'acme']);
    $task = Task::factory()->for($profile)->create([
        'ref' => 'ws-022',
        'title' => 'Wire up the export button',
        'status' => \App\Enums\TaskStatus::Review,
        'readiness_score' => 72,
        'readiness_detail' => ['score' => 72, 'light' => 'amber'],
    ]);

    $message = (new TaskAttentionNotification($task, 'review'))
        ->toSlack(new AnonymousNotifiable);

    $payload = $message->toArray();
    $sectionText = $payload['blocks'][1]['text']['text'];
    $headerText = $payload['blocks'][0]['text']['text'];

    expect($sectionText)->toContain('ws-022')
        ->toContain('Wire up the export button')
        ->toContain('Review')
        ->toContain('72')
        ->toContain(route('profiles.board', $profile));

    expect($headerText)->toContain('Acme Ltd')
        ->toContain('Awaiting review');
});
