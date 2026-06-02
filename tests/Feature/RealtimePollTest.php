<?php

use App\Enums\TaskStatus;
use App\Livewire\Activity;
use App\Livewire\Board;
use App\Livewire\Inbox;
use App\Livewire\Overview;
use App\Models\Profile;
use App\Models\Task;
use Livewire\Livewire;

it('polls the board when no modal is open', function () {
    $profile = Profile::factory()->create();

    Livewire::test(Board::class, ['profile' => $profile])
        ->assertSee('wire:poll', false);
});

it('pauses board polling while a modal/drawer is open', function () {
    $profile = Profile::factory()->create();

    Livewire::test(Board::class, ['profile' => $profile])
        ->set('showCreate', true)
        ->assertDontSee('wire:poll', false)
        ->set('showCreate', false)
        ->set('showDetail', true)
        ->assertDontSee('wire:poll', false);
});

it('polls faster when work is in flight, slower when idle', function () {
    $profile = Profile::factory()->create();

    expect(Livewire::test(Board::class, ['profile' => $profile])->instance()->pollInterval())->toBe('10s');

    Task::factory()->for($profile)->create(['status' => TaskStatus::Processing]);

    expect(Livewire::test(Board::class, ['profile' => $profile])->instance()->pollInterval())->toBe('3s');
});

it('polls the overview, inbox and activity views', function () {
    $profile = Profile::factory()->create();

    Livewire::test(Overview::class)->assertSee('wire:poll', false);
    Livewire::test(Inbox::class, ['profile' => $profile])->assertSee('wire:poll', false);
    Livewire::test(Activity::class, ['profile' => $profile])->assertSee('wire:poll', false);
});

it('keeps polling while a scoping task is open in the drawer (live agent replies)', function () {
    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Scoping]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->assertSee('wire:poll', false);
});

it('pauses polling when a non-scoping task is open so answering is not disrupted', function () {
    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::NeedsInput]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->assertDontSee('wire:poll', false);
});

it('shows a needs-scoping affordance on backlog cards', function () {
    $profile = Profile::factory()->create();
    Task::factory()->for($profile)->create(['status' => TaskStatus::Backlog]);

    Livewire::test(Board::class, ['profile' => $profile])->assertSee('Needs scoping');
});
