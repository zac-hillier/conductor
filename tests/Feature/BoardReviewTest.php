<?php

use App\Enums\TaskStatus;
use App\Livewire\Board;
use App\Models\Profile;
use App\Models\Task;
use Livewire\Livewire;

it('approves a task in review and moves it to complete', function () {
    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Review]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->call('approveTask');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Complete)
        ->and($task->events()->where('kind', 'approved')->exists())->toBeTrue();
});

it('requests changes and moves a review task back to ready with a note', function () {
    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Review]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->set('reviewNote', 'Please add tests.')
        ->call('requestChanges');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Ready)
        ->and($task->events()->where('kind', 'changes_requested')->exists())->toBeTrue()
        ->and($task->comments()->where('body', 'Please add tests.')->exists())->toBeTrue();
});

it('retries a blocked task back to ready', function () {
    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Blocked]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->call('retryTask');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Ready)
        ->and($task->events()->where('kind', 'retry_requested')->exists())->toBeTrue();
});

it('ignores approve when the task is not in review', function () {
    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->call('approveTask');

    expect($task->refresh()->status)->toBe(TaskStatus::Ready);
});
