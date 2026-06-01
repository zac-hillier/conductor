<?php

use App\Enums\TaskStatus;
use App\Jobs\ScoreTaskJob;
use App\Models\Profile;
use App\Models\Task;
use App\Services\Claude\ClaudeRunner;
use App\Services\ReadinessPromptBuilder;
use App\Services\ReadinessScorer;
use Tests\Support\FakeClaudeRunner;

function runScore(Task $task, FakeClaudeRunner $fake): void
{
    app()->instance(ClaudeRunner::class, $fake);
    $scorer = new ReadinessScorer($fake, app(ReadinessPromptBuilder::class));
    (new ScoreTaskJob($task))->handle($scorer);
}

it('persists the aggregate score and detail and records a scored event', function () {
    $fake = new FakeClaudeRunner;
    $fake->queueJson(['score' => 90, 'summary' => 's', 'blockers' => []])
        ->queueJson(['score' => 70, 'summary' => 's', 'blockers' => []])
        ->queueJson(['score' => 80, 'summary' => 's', 'blockers' => []]);

    $profile = Profile::factory()->create(['workdir' => '/tmp/conductor-readiness']);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready]);

    runScore($task, $fake);

    $task->refresh();
    expect($task->readiness_score)->toBe(80)
        ->and($task->readiness_detail['light'])->toBe('green')
        ->and($task->readiness_detail['reviewers'])->toHaveCount(3);

    $event = $task->events()->where('kind', 'scored')->firstOrFail();
    expect($event->payload['score'])->toBe(80)
        ->and($event->payload['light'])->toBe('green');
});

it('is a no-op when the task is not ready', function () {
    $fake = new FakeClaudeRunner;
    $fake->queueJson(['score' => 90, 'summary' => 's', 'blockers' => []]);

    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Backlog]);

    runScore($task, $fake);

    $task->refresh();
    expect($task->readiness_score)->toBeNull()
        ->and($task->events()->where('kind', 'scored')->exists())->toBeFalse()
        ->and($fake->calls)->toBe([]);
});
