<?php

use App\Enums\TaskStatus;
use App\Models\Profile;
use App\Models\Task;
use App\Services\ReadinessPromptBuilder;
use App\Services\ReadinessScorer;
use Tests\Support\FakeClaudeRunner;

function scorer(FakeClaudeRunner $fake): ReadinessScorer
{
    return new ReadinessScorer($fake, app(ReadinessPromptBuilder::class));
}

function readyTask(): Task
{
    $profile = Profile::factory()->create(['workdir' => '/tmp/conductor-readiness']);

    return Task::factory()->for($profile)->create([
        'status' => TaskStatus::Ready,
        'title' => 'Paginate the orders endpoint',
        'definition_of_done' => ['GET /api/orders accepts a cursor'],
        'target_paths' => ['app/Http/Controllers/OrderController.php'],
    ]);
}

it('averages reviewer scores into a green light', function () {
    $fake = new FakeClaudeRunner;
    $fake->queueJson(['score' => 90, 'summary' => 'Clear brief.', 'blockers' => []])
        ->queueJson(['score' => 70, 'summary' => 'A few gaps.', 'blockers' => ['Undefined cursor format']])
        ->queueJson(['score' => 80, 'summary' => 'Reachable paths.', 'blockers' => []]);

    $detail = scorer($fake)->score(readyTask());

    expect($detail['score'])->toBe(80)
        ->and($detail['light'])->toBe('green')
        ->and($detail['reviewers'])->toHaveCount(3)
        ->and($detail['reviewers'][1]['blockers'])->toBe(['Undefined cursor format']);
});

it('averages mid scores into an amber light', function () {
    $fake = new FakeClaudeRunner;
    $fake->queueJson(['score' => 40, 'summary' => 's', 'blockers' => []])
        ->queueJson(['score' => 60, 'summary' => 's', 'blockers' => []])
        ->queueJson(['score' => 50, 'summary' => 's', 'blockers' => []]);

    $detail = scorer($fake)->score(readyTask());

    expect($detail['score'])->toBe(50)
        ->and($detail['light'])->toBe('amber');
});

it('averages low scores into a red light', function () {
    $fake = new FakeClaudeRunner;
    $fake->queueJson(['score' => 20, 'summary' => 's', 'blockers' => []])
        ->queueJson(['score' => 30, 'summary' => 's', 'blockers' => []])
        ->queueJson(['score' => 10, 'summary' => 's', 'blockers' => []]);

    $detail = scorer($fake)->score(readyTask());

    expect($detail['score'])->toBe(20)
        ->and($detail['light'])->toBe('red');
});

it('excludes a reviewer parse failure from the mean and records its error', function () {
    $fake = new FakeClaudeRunner;
    $fake->queueJson(['score' => 90, 'summary' => 's', 'blockers' => []])
        ->queueResult(FakeClaudeRunner::text('sorry, no structured output'))
        ->queueJson(['score' => 70, 'summary' => 's', 'blockers' => []]);

    $detail = scorer($fake)->score(readyTask());

    // Mean of 90 and 70 only.
    expect($detail['score'])->toBe(80)
        ->and($detail['light'])->toBe('green');

    $failed = collect($detail['reviewers'])->firstWhere('score', null);
    expect($failed)->not->toBeNull()
        ->and($failed['error'])->not->toBeEmpty();
});

it('excludes a reviewer error result from the mean', function () {
    $fake = new FakeClaudeRunner;
    $fake->queueJson(['score' => 60, 'summary' => 's', 'blockers' => []])
        ->queueResult(FakeClaudeRunner::error())
        ->queueJson(['score' => 80, 'summary' => 's', 'blockers' => []]);

    $detail = scorer($fake)->score(readyTask());

    expect($detail['score'])->toBe(70);

    $failed = collect($detail['reviewers'])->firstWhere('score', null);
    expect($failed['error'])->toContain('permission denied');
});

it('returns a null score and red light when every reviewer fails', function () {
    $fake = new FakeClaudeRunner;
    $fake->queueResult(FakeClaudeRunner::error())
        ->queueResult(FakeClaudeRunner::text('nope'))
        ->queueResult(FakeClaudeRunner::error());

    $detail = scorer($fake)->score(readyTask());

    expect($detail['score'])->toBeNull()
        ->and($detail['light'])->toBe('red');
});

it('runs each reviewer read-only with the correct agent flag', function () {
    config()->set('conductor.readiness.reviewers', ['ag-review', 'ag-gap-analysis', 'ag-devops-review']);

    $fake = new FakeClaudeRunner;
    $fake->queueJson(['score' => 90, 'summary' => 's', 'blockers' => []])
        ->queueJson(['score' => 70, 'summary' => 's', 'blockers' => []])
        ->queueJson(['score' => 80, 'summary' => 's', 'blockers' => []]);

    scorer($fake)->score(readyTask());

    expect($fake->calls)->toHaveCount(3);

    $agents = array_map(fn (array $c) => $c['options']['agent'], $fake->calls);
    expect($agents)->toBe(['ag-review', 'ag-gap-analysis', 'ag-devops-review']);

    foreach ($fake->calls as $call) {
        expect($call['options']['allowed_tools'])->toBe(['Read', 'Grep', 'Glob'])
            ->and($call['options'])->not->toHaveKey('disallowed_tools')
            ->and($call['workdir'])->toBe('/tmp/conductor-readiness');
    }
});
