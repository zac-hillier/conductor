<?php

use App\Enums\TaskStatus;
use App\Jobs\RunTaskJob;
use App\Livewire\Board;
use App\Models\Profile;
use App\Models\Task;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

it('closes the drawer on dispatch so the task is visibly running on the board', function () {
    Bus::fake();

    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->assertSet('showDetail', true)
        ->call('dispatchTask')
        ->assertSet('showDetail', false)
        ->assertSet('selectedTaskId', null);

    expect($task->fresh()->status)->toBe(TaskStatus::Processing);
    Bus::assertDispatched(RunTaskJob::class);
});

it('re-syncs the edit status to reality after a scope action', function () {
    Bus::fake();

    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Backlog]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->assertSet('editStatus', TaskStatus::Backlog->value)
        ->call('scopeTask')
        ->assertSet('editStatus', TaskStatus::Scoping->value)
        ->assertSet('actionNotice', fn ($v) => is_string($v) && str_contains($v, 'Scoping'));
});

it('refuses to save over a task a worker is processing', function () {
    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $task = Task::factory()->for($profile)->create([
        'status' => TaskStatus::Processing,
        'title' => 'Original title',
    ]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->set('editTitle', 'Clobbered title')
        ->set('editStatus', TaskStatus::Backlog->value)
        ->call('updateTask')
        ->assertSet('editStatus', TaskStatus::Processing->value)
        ->assertSet('actionNotice', fn ($v) => is_string($v) && str_contains($v, 'background'));

    $task->refresh();
    expect($task->title)->toBe('Original title')
        ->and($task->status)->toBe(TaskStatus::Processing);
});

it('refuses to save while a scope run is in flight', function () {
    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $task = Task::factory()->for($profile)->create([
        'status' => TaskStatus::Scoping,
        'title' => 'Original title',
    ]);
    $task->runs()->create(['attempt' => 1, 'kind' => 'scope', 'started_at' => now(), 'finished_at' => null]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->set('editTitle', 'Clobbered title')
        ->call('updateTask')
        ->assertSet('actionNotice', fn ($v) => is_string($v) && str_contains($v, 'background'));

    expect($task->fresh()->title)->toBe('Original title');
});

it('still saves a normal edit when no background work owns the task', function () {
    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $task = Task::factory()->for($profile)->create([
        'status' => TaskStatus::Backlog,
        'title' => 'Original title',
        'priority' => 50,
    ]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->set('editTitle', 'Updated title')
        ->call('updateTask');

    expect($task->fresh()->title)->toBe('Updated title');
});
