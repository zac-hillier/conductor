<?php

use App\Enums\TaskStatus;
use App\Jobs\ScopeTaskJob;
use App\Livewire\Board;
use App\Models\Profile;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('starts scoping and queues the scope job from a backlog task', function () {
    Queue::fake();

    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Backlog]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->call('scopeTask');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Scoping);

    Queue::assertPushed(ScopeTaskJob::class, fn (ScopeTaskJob $job) => $job->task->is($task));

    expect($task->events()->where('kind', 'status_changed')->exists())->toBeTrue();
});

it('saves the human answer, returns to scoping and re-queues the scope job', function () {
    Queue::fake();

    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::NeedsInput]);
    $task->comments()->create([
        'author' => TaskComment::AUTHOR_AGENT,
        'body' => 'Which page should this affect?',
    ]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->set('scopeAnswer', 'The settings page.')
        ->call('continueScoping');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Scoping);

    $human = $task->comments()->where('author', TaskComment::AUTHOR_HUMAN)->firstOrFail();
    expect($human->body)->toBe('The settings page.');

    Queue::assertPushed(ScopeTaskJob::class, fn (ScopeTaskJob $job) => $job->task->is($task));
});

it('does not start scoping for a non-eligible task', function () {
    Queue::fake();

    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->call('scopeTask');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Ready);

    Queue::assertNothingPushed();
});

it('ignores an empty answer when continuing scoping', function () {
    Queue::fake();

    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::NeedsInput]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->set('scopeAnswer', '   ')
        ->call('continueScoping');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::NeedsInput)
        ->and($task->comments()->count())->toBe(0);

    Queue::assertNothingPushed();
});
