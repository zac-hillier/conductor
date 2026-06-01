<?php

use App\Enums\TaskStatus;
use App\Livewire\Board;
use App\Models\Profile;
use App\Models\Task;
use App\Models\TaskEvent;
use Livewire\Livewire;

it('creates a task in backlog via the board and renders it', function () {
    $profile = Profile::factory()->create();

    Livewire::test(Board::class, ['profile' => $profile])
        ->set('title', 'Wire up the dashboard')
        ->set('summary', 'Initial scaffold')
        ->set('priority', 60)
        ->call('createTask')
        ->assertSee('Wire up the dashboard');

    $task = $profile->tasks()->firstOrFail();

    expect($task->status)->toBe(TaskStatus::Backlog)
        ->and($task->priority)->toBe(60)
        ->and($task->ref)->not->toBeNull()
        ->and($task->events()->where('kind', 'created')->exists())->toBeTrue();
});

it('moveTask changes status and records a status_changed event with from/to', function () {
    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Backlog]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('moveTask', $task->id, TaskStatus::Research->value);

    $event = $task->events()->where('kind', 'status_changed')->firstOrFail();

    expect($task->fresh()->status)->toBe(TaskStatus::Research)
        ->and($event->payload)->toBe(['from' => 'backlog', 'to' => 'research']);
});

it('drives a task through all ten statuses with a full event trail', function () {
    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Backlog]);

    $component = Livewire::test(Board::class, ['profile' => $profile]);

    $ordered = TaskStatus::ordered();
    $previous = $ordered[0];

    foreach (array_slice($ordered, 1) as $next) {
        $component->call('moveTask', $task->id, $next->value);
        $previous = $next;
    }

    expect($task->fresh()->status)->toBe(TaskStatus::Archived);

    $events = TaskEvent::where('task_id', $task->id)
        ->where('kind', 'status_changed')
        ->orderBy('id')
        ->get();

    expect($events)->toHaveCount(9)
        ->and($events->first()->payload)->toBe(['from' => 'backlog', 'to' => 'research'])
        ->and($events->last()->payload)->toBe(['from' => 'complete', 'to' => 'archived']);
});

it('rejects priority outside 1-100', function () {
    $profile = Profile::factory()->create();

    Livewire::test(Board::class, ['profile' => $profile])
        ->set('title', 'Bad priority')
        ->set('priority', 150)
        ->call('createTask')
        ->assertHasErrors(['priority']);

    expect($profile->tasks()->count())->toBe(0);
});

it('orders cards by priority desc within a column', function () {
    $profile = Profile::factory()->create();

    Task::factory()->for($profile)->create(['status' => TaskStatus::Backlog, 'priority' => 10, 'title' => 'Low']);
    Task::factory()->for($profile)->create(['status' => TaskStatus::Backlog, 'priority' => 90, 'title' => 'High']);
    Task::factory()->for($profile)->create(['status' => TaskStatus::Backlog, 'priority' => 50, 'title' => 'Mid']);

    Livewire::test(Board::class, ['profile' => $profile])
        ->assertSeeInOrder(['High', 'Mid', 'Low']);
});

it('records events when editing title and priority', function () {
    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create([
        'title' => 'Original',
        'priority' => 50,
        'status' => TaskStatus::Backlog,
    ]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->set('editTitle', 'Renamed')
        ->set('editPriority', 80)
        ->call('updateTask');

    $task->refresh();

    expect($task->title)->toBe('Renamed')
        ->and($task->priority)->toBe(80)
        ->and($task->events()->where('kind', 'updated')->exists())->toBeTrue()
        ->and($task->events()->where('kind', 'priority_changed')->firstOrFail()->payload)
        ->toBe(['from' => 50, 'to' => 80]);
});

it('deletes a task and cascades its events', function () {
    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create();
    $task->recordEvent('created');

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->call('deleteTask');

    expect(Task::find($task->id))->toBeNull()
        ->and(DB::table('task_events')->where('task_id', $task->id)->count())->toBe(0);
});

it('keeps selectedTaskId as the task id and uses a separate boolean for the drawer', function () {
    // Regression: the detail flyout must bind its open-state to the boolean
    // $showDetail, NOT the int $selectedTaskId — binding the modal to the int
    // cast its open=true to 1 and clobbered the selected id (delete -> 404).
    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create();

    $component = Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id);

    $component->assertSet('selectedTaskId', $task->id)
        ->assertSet('showDetail', true);

    // Closing the flyout (showDetail -> false) clears the selection.
    $component->set('showDetail', false)
        ->assertSet('selectedTaskId', null);
});
