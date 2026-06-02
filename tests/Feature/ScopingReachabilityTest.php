<?php

use App\Enums\TaskStatus;
use App\Jobs\ScopeTaskJob;
use App\Livewire\Board;
use App\Models\Profile;
use App\Models\Task;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

it('enterScoping dispatches a scope run for a scoping task with none active', function () {
    Bus::fake();
    $task = Task::factory()->for(Profile::factory())->create(['status' => TaskStatus::Scoping]);

    $task->enterScoping();

    Bus::assertDispatched(ScopeTaskJob::class);
});

it('enterScoping is a no-op when a scope run is already in flight', function () {
    Bus::fake();
    $task = Task::factory()->for(Profile::factory())->create(['status' => TaskStatus::Scoping]);
    $task->runs()->create(['attempt' => 1, 'kind' => 'scope', 'started_at' => now(), 'finished_at' => null]);

    $task->enterScoping();

    Bus::assertNotDispatched(ScopeTaskJob::class);
});

it('enterScoping is a no-op for a non-scoping task', function () {
    Bus::fake();
    $task = Task::factory()->for(Profile::factory())->create(['status' => TaskStatus::Ready]);

    $task->enterScoping();

    Bus::assertNotDispatched(ScopeTaskJob::class);
});

it('hasActiveScopeRun reflects an unfinished scope run', function () {
    $task = Task::factory()->for(Profile::factory())->create();

    expect($task->hasActiveScopeRun())->toBeFalse();

    $task->runs()->create(['attempt' => 1, 'kind' => 'scope', 'started_at' => now()]);
    expect($task->fresh()->hasActiveScopeRun())->toBeTrue();

    $task->runs()->update(['finished_at' => now()]);
    expect($task->fresh()->hasActiveScopeRun())->toBeFalse();
});

it('moving a task to scoping via the board starts a scope run', function () {
    Bus::fake();
    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Backlog]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('moveTask', $task->id, 'scoping');

    Bus::assertDispatched(ScopeTaskJob::class);
});

it('saving a task to scoping via the edit form starts a scope run', function () {
    Bus::fake();
    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Backlog, 'title' => 'x', 'priority' => 50]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->set('editStatus', TaskStatus::Scoping->value)
        ->call('updateTask');

    Bus::assertDispatched(ScopeTaskJob::class);
});
