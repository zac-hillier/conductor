<?php

use App\Enums\TaskStatus;
use App\Livewire\Inbox;
use App\Livewire\Overview;
use App\Models\Profile;
use App\Models\Task;
use Livewire\Livewire;

it('lists only review, needs_input and blocked tasks for the profile', function () {
    $profile = Profile::factory()->create();

    $review = Task::factory()->for($profile)->create(['status' => TaskStatus::Review, 'title' => 'Review me']);
    $needsInput = Task::factory()->for($profile)->create(['status' => TaskStatus::NeedsInput, 'title' => 'Needs input here']);
    $blocked = Task::factory()->for($profile)->create(['status' => TaskStatus::Blocked, 'title' => 'Blocked task']);
    $ready = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready, 'title' => 'Ready task']);
    $complete = Task::factory()->for($profile)->create(['status' => TaskStatus::Complete, 'title' => 'Complete task']);

    Livewire::test(Inbox::class, ['profile' => $profile])
        ->assertSee('Review me')
        ->assertSee('Needs input here')
        ->assertSee('Blocked task')
        ->assertDontSee('Ready task')
        ->assertDontSee('Complete task');
});

it('excludes tasks from other profiles', function () {
    $profile = Profile::factory()->create();
    $other = Profile::factory()->create();
    Task::factory()->for($other)->create(['status' => TaskStatus::Review, 'title' => 'Other profile task']);

    Livewire::test(Inbox::class, ['profile' => $profile])
        ->assertDontSee('Other profile task');
});

it('shows the correct attention count on the overview', function () {
    $profile = Profile::factory()->create();
    Task::factory()->for($profile)->create(['status' => TaskStatus::Review]);
    Task::factory()->for($profile)->create(['status' => TaskStatus::Blocked]);
    Task::factory()->for($profile)->create(['status' => TaskStatus::NeedsInput]);
    Task::factory()->for($profile)->create(['status' => TaskStatus::Ready]);
    Task::factory()->for($profile)->create(['status' => TaskStatus::Complete]);

    Livewire::test(Overview::class)
        ->assertSeeHtml('data-profile="'.$profile->slug.'" data-attention="3"');
});
