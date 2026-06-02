<?php

use App\Enums\TaskStatus;
use App\Jobs\RunTaskJob;
use App\Models\Profile;
use App\Models\Task;
use App\Services\Claude\ClaudeRunner;
use App\Services\TaskPromptBuilder;
use App\Support\PolicyDefaults;
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
        ->and($fake->lastOptions['disallowed_tools'])->toBe(PolicyDefaults::RESTRICTED_TOOLS);

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

it('runs end-to-end from an already-claimed processing task', function () {
    Storage::fake('local');

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::success();
    bindFakeRunner($fake);

    $profile = Profile::factory()->customer()->create(['workdir' => '/tmp/conductor-work']);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready]);

    // Dispatcher claims first, then the job runs against a processing task.
    expect($task->claim())->toBeTrue();

    (new RunTaskJob($task))->handle($fake, app(TaskPromptBuilder::class));

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Review)
        ->and($task->runs()->count())->toBe(1)
        ->and($task->events()->where('kind', 'claimed')->count())->toBe(1);
});

it('runs via dispatchSync from a ready task despite being unique', function () {
    Storage::fake('local');

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::success();
    bindFakeRunner($fake);

    $profile = Profile::factory()->personal()->create(['workdir' => '/tmp/conductor-work']);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready]);

    RunTaskJob::dispatchSync($task);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Complete)
        ->and($task->runs()->count())->toBe(1);
});

it('captures requested approvals and routes the task to review', function () {
    Storage::fake('local');

    $output = <<<'TXT'
    I made the documentation changes but could not push.

    APPROVALS_REQUESTED:
    - Bash(git push:*) :: git push origin feature/docs :: the change needs to reach the remote
    - Bash(git tag:*) :: git tag v1.2.0 :: tag the release
    TXT;

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::text($output);
    bindFakeRunner($fake);

    $profile = Profile::factory()->personal()->create(['workdir' => '/tmp/conductor-work']);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready]);

    (new RunTaskJob($task))->handle($fake, app(TaskPromptBuilder::class));

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Review)
        ->and($task->approvals()->count())->toBe(2);

    $push = $task->approvals()->where('capability', 'Bash(git push:*)')->firstOrFail();
    expect($push->decision)->toBe('pending')
        ->and($push->command)->toBe('git push origin feature/docs')
        ->and($push->reason)->toBe('the change needs to reach the remote')
        ->and($push->run_id)->toBe($task->runs()->firstOrFail()->id);

    expect($task->events()->where('kind', 'approvals_requested')->exists())->toBeTrue();
});

it('creates no approvals when the result has no block', function () {
    Storage::fake('local');

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::text('All done, nothing restricted was needed.');
    bindFakeRunner($fake);

    $profile = Profile::factory()->personal()->create(['workdir' => '/tmp/conductor-work']);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready]);

    (new RunTaskJob($task))->handle($fake, app(TaskPromptBuilder::class));

    $task->refresh();
    expect($task->approvals()->count())->toBe(0)
        ->and($task->status)->toBe(TaskStatus::Complete);
});

it('excludes a granted capability from the disallowed tools on the next attempt', function () {
    Storage::fake('local');

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::success();
    bindFakeRunner($fake);

    $profile = Profile::factory()->customer()->create(['workdir' => '/tmp/conductor-work']);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready]);

    $task->approvals()->create([
        'capability' => 'Bash(git push:*)',
        'decision' => 'granted',
        'decided_at' => now(),
    ]);

    (new RunTaskJob($task))->handle($fake, app(TaskPromptBuilder::class));

    expect($fake->lastOptions['disallowed_tools'])->not->toContain('Bash(git push:*)')
        ->and($fake->lastOptions['disallowed_tools'])->toContain('Bash(git commit:*)')
        ->and($fake->lastOptions['disallowed_tools'])->toContain('Bash(git tag:*)');
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
