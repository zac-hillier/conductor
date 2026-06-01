<?php

use App\Enums\TaskStatus;
use App\Jobs\RunTaskJob;
use App\Livewire\Board;
use App\Models\Profile;
use App\Models\Task;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('shows a dispatch button for a ready task and queues the job', function () {
    Queue::fake();

    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->assertSee('Dispatch')
        ->call('dispatchTask');

    Queue::assertPushed(RunTaskJob::class, fn (RunTaskJob $job) => $job->task->is($task));
});

it('does not queue a job for a task that is not ready', function () {
    Queue::fake();

    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Backlog]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->assertDontSee('Dispatch')
        ->call('dispatchTask');

    Queue::assertNothingPushed();
});
