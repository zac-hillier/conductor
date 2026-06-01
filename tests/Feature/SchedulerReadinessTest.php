<?php

use App\Enums\TaskStatus;
use App\Jobs\RunTaskJob;
use App\Livewire\Board;
use App\Models\Profile;
use App\Models\Task;
use App\Services\DispatchScheduler;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('conductor.readiness.enabled', true);
    config()->set('conductor.readiness.min_auto_dispatch', 50);
});

it('auto-dispatches a ready task scored at or above the threshold', function () {
    Bus::fake();

    $profile = Profile::factory()->personal()->create(['concurrency_cap' => 2]);
    $task = Task::factory()->for($profile)->create([
        'status' => TaskStatus::Ready,
        'priority' => 90,
        'readiness_score' => 50,
    ]);

    app(DispatchScheduler::class)->tick();

    expect($task->fresh()->status)->toBe(TaskStatus::Processing);
    Bus::assertDispatched(RunTaskJob::class, fn (RunTaskJob $job) => $job->task->is($task));
});

it('holds an unscored ready task from auto-dispatch', function () {
    Bus::fake();

    $profile = Profile::factory()->personal()->create(['concurrency_cap' => 2]);
    $task = Task::factory()->for($profile)->create([
        'status' => TaskStatus::Ready,
        'priority' => 90,
        'readiness_score' => null,
    ]);

    app(DispatchScheduler::class)->tick();

    expect($task->fresh()->status)->toBe(TaskStatus::Ready);
    Bus::assertNothingDispatched();
});

it('holds a below-threshold ready task from auto-dispatch', function () {
    Bus::fake();

    $profile = Profile::factory()->personal()->create(['concurrency_cap' => 2]);
    $task = Task::factory()->for($profile)->create([
        'status' => TaskStatus::Ready,
        'priority' => 90,
        'readiness_score' => 49,
    ]);

    app(DispatchScheduler::class)->tick();

    expect($task->fresh()->status)->toBe(TaskStatus::Ready);
    Bus::assertNothingDispatched();
});

it('still lets a human manually dispatch a red, unscored task', function () {
    Bus::fake();

    $profile = Profile::factory()->personal()->create(['concurrency_cap' => 2]);
    $task = Task::factory()->for($profile)->create([
        'status' => TaskStatus::Ready,
        'priority' => 90,
        'readiness_score' => 10,
    ]);

    app(DispatchScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Ready);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->call('dispatchTask');

    expect($task->fresh()->status)->toBe(TaskStatus::Processing);
    Bus::assertDispatchedTimes(RunTaskJob::class, 1);
});

it('dispatches qualifying tasks but skips held ones in the same tick', function () {
    Bus::fake();

    $profile = Profile::factory()->personal()->create(['concurrency_cap' => 5]);

    $qualifying = Task::factory()->for($profile)->create([
        'status' => TaskStatus::Ready,
        'priority' => 90,
        'readiness_score' => 80,
    ]);
    $held = Task::factory()->for($profile)->create([
        'status' => TaskStatus::Ready,
        'priority' => 95,
        'readiness_score' => null,
    ]);

    app(DispatchScheduler::class)->tick();

    expect($qualifying->fresh()->status)->toBe(TaskStatus::Processing)
        ->and($held->fresh()->status)->toBe(TaskStatus::Ready);

    Bus::assertDispatchedTimes(RunTaskJob::class, 1);
});
