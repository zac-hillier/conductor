<?php

use App\Enums\TaskStatus;
use App\Jobs\RunTaskJob;
use App\Models\Profile;
use App\Models\Task;
use App\Services\Claude\ClaudeRunner;
use App\Services\TaskPromptBuilder;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeClaudeRunner;

function bindFakeRunner(FakeClaudeRunner $fake): void
{
    app()->instance(ClaudeRunner::class, $fake);
}

it('dispatches a ready task and moves it to review on success', function () {
    Storage::fake('local');

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::success();
    bindFakeRunner($fake);

    $profile = Profile::factory()->customer()->create(['workdir' => '/tmp/conductor-work']);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready]);

    (new RunTaskJob($task))->handle($fake, app(TaskPromptBuilder::class));

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Review);

    $run = $task->runs()->firstOrFail();
    expect($run->outcome)->toBe('success')
        ->and($run->summary)->toBe('Implemented the change and verified it.')
        ->and((float) $run->cost)->toBe(0.0421)
        ->and($run->token_count)->toBe(2000)
        ->and($run->session_id)->toBe('sess-success-123')
        ->and($run->log_ref)->not->toBeNull();

    Storage::disk('local')->assertExists($run->log_ref);

    expect($task->events()->where('kind', 'dispatched')->exists())->toBeTrue()
        ->and($task->events()->where('kind', 'run_completed')->exists())->toBeTrue();
});

it('moves a task to blocked on an error result', function () {
    Storage::fake('local');

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::error();
    bindFakeRunner($fake);

    $profile = Profile::factory()->create(['workdir' => '/tmp/conductor-work']);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready]);

    (new RunTaskJob($task))->handle($fake, app(TaskPromptBuilder::class));

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Blocked);

    $run = $task->runs()->firstOrFail();
    expect($run->outcome)->toBe('failed');

    expect($task->events()->where('kind', 'run_failed')->exists())->toBeTrue()
        ->and($task->events()->where('kind', 'run_completed')->exists())->toBeFalse();
});

it('is a no-op when the task is not ready', function () {
    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::success();
    bindFakeRunner($fake);

    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Backlog]);

    (new RunTaskJob($task))->handle($fake, app(TaskPromptBuilder::class));

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Backlog)
        ->and($task->runs()->count())->toBe(0)
        ->and($fake->lastPrompt)->toBeNull();
});

it('auto-completes a personal task and passes bypass permissions to the runner', function () {
    Storage::fake('local');

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::success();
    bindFakeRunner($fake);

    $profile = Profile::factory()->personal()->create(['workdir' => '/tmp/conductor-work']);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready]);

    (new RunTaskJob($task))->handle($fake, app(TaskPromptBuilder::class));

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Complete)
        ->and($fake->lastOptions['permission_mode'])->toBe('bypassPermissions')
        ->and($fake->lastOptions['disallowed_tools'])->toBe([]);

    expect($task->events()->where('kind', 'completed')->exists())->toBeTrue();
});

it('sends a customer task to review and passes the git disallow patterns', function () {
    Storage::fake('local');

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::success();
    bindFakeRunner($fake);

    $profile = Profile::factory()->customer()->create(['workdir' => '/tmp/conductor-work']);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready]);

    (new RunTaskJob($task))->handle($fake, app(TaskPromptBuilder::class));

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Review)
        ->and($fake->lastOptions['permission_mode'])->toBe('acceptEdits')
        ->and($fake->lastOptions['disallowed_tools'])->toContain('Bash(git push:*)')
        ->and($fake->lastOptions['disallowed_tools'])->toContain('Bash(git commit:*)');

    expect($task->events()->where('kind', 'awaiting_review')->exists())->toBeTrue();
});

it('increments attempt on retry of a blocked task', function () {
    Storage::fake('local');

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::success();
    bindFakeRunner($fake);

    $profile = Profile::factory()->create(['workdir' => '/tmp/conductor-work']);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready]);

    (new RunTaskJob($task))->handle($fake, app(TaskPromptBuilder::class));

    $task->update(['status' => TaskStatus::Blocked]);
    (new RunTaskJob($task))->handle($fake, app(TaskPromptBuilder::class));

    expect($task->runs()->pluck('attempt')->sort()->values()->all())->toBe([1, 2]);
});
