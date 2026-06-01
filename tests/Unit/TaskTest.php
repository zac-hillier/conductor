<?php

use App\Enums\TaskStatus;
use App\Models\Task;

it('creates a task with backlog status and priority 50 by default', function () {
    $task = Task::factory()->create();

    expect($task->status)->toBe(TaskStatus::Backlog)
        ->and($task->priority)->toBe(50);
});

it('claims a ready task once and refuses a second claim', function () {
    $task = Task::factory()->create(['status' => TaskStatus::Ready]);

    expect($task->claim())->toBeTrue()
        ->and($task->status)->toBe(TaskStatus::Processing)
        ->and($task->fresh()->status)->toBe(TaskStatus::Processing);

    $again = $task->fresh();
    expect($again->claim())->toBeFalse()
        ->and($again->fresh()->status)->toBe(TaskStatus::Processing);

    expect($task->events()->where('kind', 'claimed')->count())->toBe(1);
});

it('does not claim a task that is not ready', function () {
    $task = Task::factory()->create(['status' => TaskStatus::Backlog]);

    expect($task->claim())->toBeFalse()
        ->and($task->fresh()->status)->toBe(TaskStatus::Backlog);
});

it('round-trips the definition_of_done array cast', function () {
    $dod = ['tests pass', 'build succeeds'];

    $task = Task::factory()->create([
        'definition_of_done' => $dod,
    ]);

    expect($task->fresh()->definition_of_done)->toBe($dod);
});
