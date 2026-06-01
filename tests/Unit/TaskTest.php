<?php

use App\Enums\TaskStatus;
use App\Models\Task;

it('creates a task with backlog status and priority 50 by default', function () {
    $task = Task::factory()->create();

    expect($task->status)->toBe(TaskStatus::Backlog)
        ->and($task->priority)->toBe(50);
});

it('round-trips the definition_of_done array cast', function () {
    $dod = ['tests pass', 'build succeeds'];

    $task = Task::factory()->create([
        'definition_of_done' => $dod,
    ]);

    expect($task->fresh()->definition_of_done)->toBe($dod);
});
