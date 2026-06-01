<?php

use App\Enums\TaskStatus;
use App\Jobs\ScopeTaskJob;
use App\Jobs\ScoreTaskJob;
use App\Models\Profile;
use App\Models\Task;
use App\Models\TaskComment;
use App\Services\Claude\ClaudeRunner;
use App\Services\ScopePromptBuilder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeClaudeRunner;

function runScope(Task $task, FakeClaudeRunner $fake): void
{
    app()->instance(ClaudeRunner::class, $fake);
    (new ScopeTaskJob($task))->handle($fake, app(ScopePromptBuilder::class));
}

it('asks questions and moves a vague task to needs input', function () {
    Storage::fake('local');

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::json([
        'ready' => false,
        'questions' => ['Which page should this affect?', 'What is the acceptance criterion?'],
        'title' => null,
        'summary' => null,
        'definition_of_done' => [],
        'constraints' => [],
        'target_paths' => [],
        'rationale' => 'The brief is objectless.',
    ]);

    $profile = Profile::factory()->create(['workdir' => '/tmp/conductor-scope']);
    $task = Task::factory()->for($profile)->create([
        'status' => TaskStatus::Scoping,
        'title' => 'Make it better',
    ]);

    runScope($task, $fake);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::NeedsInput);

    $comment = $task->comments()->where('author', TaskComment::AUTHOR_AGENT)->firstOrFail();
    expect($comment->body)->toContain('Which page should this affect?')
        ->and($comment->body)->toContain('What is the acceptance criterion?');

    $run = $task->runs()->where('kind', 'scope')->firstOrFail();
    expect($run->kind)->toBe('scope')
        ->and((float) $run->cost)->toBe(0.0185)
        ->and($run->token_count)->toBe(1200);

    $event = $task->events()->where('kind', 'scope_questions')->firstOrFail();
    expect($event->payload['count'])->toBe(2);
    expect($task->events()->where('kind', 'scoping_started')->exists())->toBeTrue();
});

it('runs with read-only allowed tools and no edit or bash tools', function () {
    Storage::fake('local');

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::json([
        'ready' => false,
        'questions' => ['What exactly?'],
    ]);

    $profile = Profile::factory()->create(['workdir' => '/tmp/conductor-scope']);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Scoping]);

    runScope($task, $fake);

    expect($fake->lastOptions['allowed_tools'])->toBe(['Read', 'Grep', 'Glob'])
        ->and($fake->lastOptions)->not->toHaveKey('disallowed_tools');

    $tools = $fake->lastOptions['allowed_tools'];
    expect($tools)->not->toContain('Edit')
        ->and($tools)->not->toContain('Write')
        ->and($tools)->not->toContain('Bash');
});

it('refines the task and moves it to ready when the agent reports it is clear', function () {
    Storage::fake('local');

    $profile = Profile::factory()->create(['workdir' => '/tmp/conductor-scope']);
    $task = Task::factory()->for($profile)->create([
        'status' => TaskStatus::Scoping,
        'title' => 'Make it better',
    ]);

    // First pass: vague, agent asks questions.
    $vague = new FakeClaudeRunner;
    $vague->result = FakeClaudeRunner::json([
        'ready' => false,
        'questions' => ['Which endpoint?'],
    ]);
    runScope($task, $vague);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::NeedsInput);

    // Human answers, task returns to scoping.
    $task->comments()->create([
        'author' => TaskComment::AUTHOR_HUMAN,
        'body' => 'The /api/orders endpoint, it should paginate.',
    ]);
    $task->update(['status' => TaskStatus::Scoping]);

    // Second pass: clear, agent fills the definition of done.
    $clear = new FakeClaudeRunner;
    $clear->result = FakeClaudeRunner::json([
        'ready' => true,
        'questions' => [],
        'title' => 'Paginate the orders endpoint',
        'summary' => 'Add cursor pagination to GET /api/orders.',
        'definition_of_done' => [
            'GET /api/orders accepts a cursor query parameter',
            'Response includes a next_cursor field',
        ],
        'constraints' => ['Preserve the existing response envelope'],
        'target_paths' => ['app/Http/Controllers/OrderController.php'],
        'rationale' => 'Now concrete and verifiable.',
    ]);
    runScope($task, $clear);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Ready)
        ->and($task->title)->toBe('Paginate the orders endpoint')
        ->and($task->summary)->toBe('Add cursor pagination to GET /api/orders.')
        ->and($task->definition_of_done)->toBe([
            'GET /api/orders accepts a cursor query parameter',
            'Response includes a next_cursor field',
        ])
        ->and($task->constraints)->toBe(['Preserve the existing response envelope'])
        ->and($task->target_paths)->toBe(['app/Http/Controllers/OrderController.php']);

    expect($task->events()->where('kind', 'scoped_ready')->exists())->toBeTrue();
});

it('queues a readiness scoring job when scoping marks a task ready', function () {
    Storage::fake('local');
    Bus::fake();

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::json([
        'ready' => true,
        'title' => 'Paginate the orders endpoint',
        'definition_of_done' => ['GET /api/orders accepts a cursor'],
    ]);

    $profile = Profile::factory()->create(['workdir' => '/tmp/conductor-scope']);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Scoping]);

    runScope($task, $fake);

    expect($task->fresh()->status)->toBe(TaskStatus::Ready);

    Bus::assertDispatched(ScoreTaskJob::class, fn (ScoreTaskJob $job) => $job->task->is($task));
});

it('moves to needs input and records the raw response on a parse failure', function () {
    Storage::fake('local');

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::text('I was unable to produce structured output, sorry.');

    $profile = Profile::factory()->create(['workdir' => '/tmp/conductor-scope']);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Scoping]);

    runScope($task, $fake);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::NeedsInput);

    $comment = $task->comments()->where('author', TaskComment::AUTHOR_AGENT)->firstOrFail();
    expect($comment->body)->toContain('I was unable to produce structured output');

    expect($task->events()->where('kind', 'scope_failed')->exists())->toBeTrue();
});

it('is a no-op when the task is not in scoping', function () {
    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::json(['ready' => true]);

    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Backlog]);

    runScope($task, $fake);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Backlog)
        ->and($task->runs()->count())->toBe(0)
        ->and($fake->lastPrompt)->toBeNull();
});
