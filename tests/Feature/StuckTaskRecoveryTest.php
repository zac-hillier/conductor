<?php

use App\Enums\TaskStatus;
use App\Models\Profile;
use App\Models\Task;
use App\Services\StuckTaskRecovery;

function staleCutoff(): int
{
    return (int) config('conductor.claude.timeout', 600)
        + (int) config('conductor.recovery.grace', 120)
        + 60;
}

function makeStuckTask(): Task
{
    $profile = Profile::factory()->customer()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Processing]);

    $task->runs()->create([
        'attempt' => 1,
        'kind' => 'execute',
        'started_at' => now()->subSeconds(staleCutoff()),
        'finished_at' => null,
    ]);

    return $task;
}

it('recovers a processing task whose execute run is stale and unfinished', function () {
    $task = makeStuckTask();

    $recovered = app(StuckTaskRecovery::class)->recover();

    expect($recovered)->toHaveCount(1);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Blocked);

    $run = $task->runs()->firstOrFail();
    expect($run->outcome)->toBe('failed')
        ->and($run->finished_at)->not->toBeNull();

    expect($task->events()->where('kind', 'recovered')->exists())->toBeTrue()
        ->and($task->comments()->where('author', 'agent')->exists())->toBeTrue();
});

it('does not recover a recent processing task', function () {
    $profile = Profile::factory()->customer()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Processing]);

    $task->runs()->create([
        'attempt' => 1,
        'kind' => 'execute',
        'started_at' => now()->subSeconds(10),
        'finished_at' => null,
    ]);

    $recovered = app(StuckTaskRecovery::class)->recover();

    expect($recovered)->toBeEmpty();
    expect($task->fresh()->status)->toBe(TaskStatus::Processing);
});

it('includes a transcript snippet when the session file exists', function () {
    $profile = Profile::factory()->customer()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Processing]);

    $transcript = sys_get_temp_dir().'/conductor-recovery-'.uniqid().'.jsonl';
    file_put_contents($transcript, "first line\nlast recorded activity here\n");

    $task->runs()->create([
        'attempt' => 1,
        'kind' => 'execute',
        'started_at' => now()->subSeconds(staleCutoff()),
        'finished_at' => null,
        'claude_session_path' => $transcript,
    ]);

    app(StuckTaskRecovery::class)->recover();

    $comment = $task->comments()->where('author', 'agent')->firstOrFail();
    expect($comment->body)->toContain('last recorded activity here');

    @unlink($transcript);
});

it('changes nothing on a dry run', function () {
    $task = makeStuckTask();

    $recovered = app(StuckTaskRecovery::class)->recover(dryRun: true);

    expect($recovered)->toHaveCount(1);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Processing);

    $run = $task->runs()->firstOrFail();
    expect($run->finished_at)->toBeNull()
        ->and($run->outcome)->toBeNull();

    expect($task->events()->where('kind', 'recovered')->exists())->toBeFalse()
        ->and($task->comments()->count())->toBe(0);
});

it('reports nothing to recover via the command dry run', function () {
    makeStuckTask();

    $this->artisan('conductor:recover --dry-run')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(Task::query()->where('status', TaskStatus::Processing->value)->count())->toBe(1);
});
