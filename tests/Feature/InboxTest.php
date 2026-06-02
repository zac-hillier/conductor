<?php

use App\Enums\TaskStatus;
use App\Jobs\RunTaskJob;
use App\Livewire\Inbox;
use App\Livewire\Overview;
use App\Models\Profile;
use App\Models\Task;
use Illuminate\Support\Facades\Bus;
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

it('lists pending approvals for the profile and excludes other profiles', function () {
    $profile = Profile::factory()->create();
    $other = Profile::factory()->create();

    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Review]);
    $task->approvals()->create([
        'capability' => 'Bash(git push:*)',
        'command' => 'git push origin main',
        'reason' => 'deliver the change',
        'decision' => 'pending',
    ]);

    $otherTask = Task::factory()->for($other)->create(['status' => TaskStatus::Review]);
    $otherTask->approvals()->create([
        'capability' => 'Bash(terraform apply:*)',
        'decision' => 'pending',
    ]);

    Livewire::test(Inbox::class, ['profile' => $profile])
        ->assertSee('Bash(git push:*)')
        ->assertSee('git push origin main')
        ->assertSee('deliver the change')
        ->assertDontSee('Bash(terraform apply:*)');
});

it('grants a pending approval and re-dispatches the task', function () {
    Bus::fake();

    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Review]);
    $approval = $task->approvals()->create([
        'capability' => 'Bash(git push:*)',
        'decision' => 'pending',
    ]);

    Livewire::test(Inbox::class, ['profile' => $profile])
        ->call('grant', $approval->id);

    $approval->refresh();
    expect($approval->decision)->toBe('granted')
        ->and($approval->decided_at)->not->toBeNull();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Processing)
        ->and($task->events()->where('kind', 'approval_granted')->exists())->toBeTrue();

    Bus::assertDispatched(RunTaskJob::class);
});

it('denies a pending approval without dispatching', function () {
    Bus::fake();

    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Review]);
    $approval = $task->approvals()->create([
        'capability' => 'Bash(git push:*)',
        'decision' => 'pending',
    ]);

    Livewire::test(Inbox::class, ['profile' => $profile])
        ->call('deny', $approval->id);

    $approval->refresh();
    expect($approval->decision)->toBe('denied')
        ->and($approval->decided_at)->not->toBeNull();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Review)
        ->and($task->events()->where('kind', 'approval_denied')->exists())->toBeTrue();

    Bus::assertNothingDispatched();
});

it('does not act on an approval from another profile', function () {
    Bus::fake();

    $profile = Profile::factory()->create();
    $other = Profile::factory()->create();
    $otherTask = Task::factory()->for($other)->create(['status' => TaskStatus::Review]);
    $approval = $otherTask->approvals()->create([
        'capability' => 'Bash(git push:*)',
        'decision' => 'pending',
    ]);

    Livewire::test(Inbox::class, ['profile' => $profile])
        ->call('grant', $approval->id);

    $approval->refresh();
    expect($approval->decision)->toBe('pending');

    Bus::assertNothingDispatched();
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
