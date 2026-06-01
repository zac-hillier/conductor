<?php

use App\Livewire\Activity;
use App\Models\Profile;
use App\Models\Task;
use Livewire\Livewire;

it('lists events for the profile and filters by text', function () {
    $profile = Profile::factory()->create();

    $alpha = Task::factory()->for($profile)->create(['ref' => 'PROJ-001', 'title' => 'Alpha task']);
    $beta = Task::factory()->for($profile)->create(['ref' => 'PROJ-002', 'title' => 'Beta task']);

    $alpha->recordEvent('status_changed', ['from' => 'backlog', 'to' => 'research']);
    $beta->recordEvent('status_changed', ['from' => 'backlog', 'to' => 'ready']);

    Livewire::test(Activity::class, ['profile' => $profile])
        ->assertSee('PROJ-001')
        ->assertSee('PROJ-002')
        ->set('search', 'Alpha')
        ->assertSee('PROJ-001')
        ->assertDontSee('PROJ-002');
});

it('filters activity by kind', function () {
    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create(['ref' => 'PROJ-001']);

    $task->recordEvent('created');
    $task->recordEvent('status_changed', ['from' => 'backlog', 'to' => 'ready']);

    Livewire::test(Activity::class, ['profile' => $profile])
        ->set('kind', 'created')
        ->assertSee('Task created')
        ->assertDontSee('Backlog → Ready');
});

it('does not show events from other profiles', function () {
    $profile = Profile::factory()->create();
    $other = Profile::factory()->create();

    Task::factory()->for($other)->create(['ref' => 'OTHER-001'])->recordEvent('created');

    Livewire::test(Activity::class, ['profile' => $profile])
        ->assertDontSee('OTHER-001');
});
