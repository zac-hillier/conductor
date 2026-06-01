<?php

use App\Enums\TaskStatus;
use App\Jobs\ScoreTaskJob;
use App\Livewire\Board;
use App\Models\Profile;
use App\Models\Task;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('queues a readiness scoring job for a ready task', function () {
    Queue::fake();

    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->call('scoreTask');

    Queue::assertPushed(ScoreTaskJob::class, fn (ScoreTaskJob $job) => $job->task->is($task));
    expect($task->events()->where('kind', 'score_requested')->exists())->toBeTrue();
});

it('does not score a task that is not ready', function () {
    Queue::fake();

    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Backlog]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->call('scoreTask');

    Queue::assertNothingPushed();
});
