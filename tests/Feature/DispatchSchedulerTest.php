<?php

use App\Enums\TaskStatus;
use App\Jobs\RunTaskJob;
use App\Livewire\Board;
use App\Models\Profile;
use App\Models\Task;
use App\Services\DispatchScheduler;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

function scheduler(): DispatchScheduler
{
    return app(DispatchScheduler::class);
}

// These tests exercise the cap/priority/ordering logic, not the readiness
// gate. The gate has dedicated coverage in SchedulerReadinessTest.
beforeEach(function () {
    config()->set('conductor.readiness.enabled', false);
});

it('dispatches up to the cap, highest priority first, and claims them', function () {
    Bus::fake();

    $profile = Profile::factory()->personal()->create(['concurrency_cap' => 2]);

    $low = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready, 'priority' => 10]);
    $mid = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready, 'priority' => 50]);
    $high = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready, 'priority' => 90]);

    scheduler()->tick();

    expect($high->fresh()->status)->toBe(TaskStatus::Processing)
        ->and($mid->fresh()->status)->toBe(TaskStatus::Processing)
        ->and($low->fresh()->status)->toBe(TaskStatus::Ready);

    Bus::assertDispatched(RunTaskJob::class, fn (RunTaskJob $job) => $job->task->is($high));
    Bus::assertDispatched(RunTaskJob::class, fn (RunTaskJob $job) => $job->task->is($mid));
    Bus::assertDispatchedTimes(RunTaskJob::class, 2);
});

it('dispatches nothing when inflight is already at the cap', function () {
    Bus::fake();

    $profile = Profile::factory()->personal()->create(['concurrency_cap' => 2]);

    Task::factory()->for($profile)->count(2)->create(['status' => TaskStatus::Processing]);
    Task::factory()->for($profile)->create(['status' => TaskStatus::Ready, 'priority' => 90]);

    scheduler()->tick();

    Bus::assertNothingDispatched();
});

it('dispatches exactly one when one slot remains', function () {
    Bus::fake();

    $profile = Profile::factory()->personal()->create(['concurrency_cap' => 2]);

    Task::factory()->for($profile)->create(['status' => TaskStatus::Processing]);
    $top = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready, 'priority' => 90]);
    $other = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready, 'priority' => 30]);

    scheduler()->tick();

    expect($top->fresh()->status)->toBe(TaskStatus::Processing)
        ->and($other->fresh()->status)->toBe(TaskStatus::Ready);

    Bus::assertDispatchedTimes(RunTaskJob::class, 1);
    Bus::assertDispatched(RunTaskJob::class, fn (RunTaskJob $job) => $job->task->is($top));
});

it('leaves lower-priority tasks ready when slots run out', function () {
    Bus::fake();

    $profile = Profile::factory()->personal()->create(['concurrency_cap' => 2]);

    $p90 = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready, 'priority' => 90]);
    $p50 = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready, 'priority' => 50]);
    $p10 = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready, 'priority' => 10]);

    scheduler()->tick();

    expect($p90->fresh()->status)->toBe(TaskStatus::Processing)
        ->and($p50->fresh()->status)->toBe(TaskStatus::Processing)
        ->and($p10->fresh()->status)->toBe(TaskStatus::Ready);
});

it('skips a profile whose auto_dispatch is off but manual dispatch still works', function () {
    Bus::fake();

    $profile = Profile::factory()->customer()->create(['concurrency_cap' => 3]);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready, 'priority' => 90]);

    scheduler()->tick();

    expect($task->fresh()->status)->toBe(TaskStatus::Ready);
    Bus::assertNothingDispatched();

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->call('dispatchTask');

    expect($task->fresh()->status)->toBe(TaskStatus::Processing);
    Bus::assertDispatchedTimes(RunTaskJob::class, 1);
});

it('dry-run lists the ordered plan but claims and dispatches nothing', function () {
    Bus::fake();

    $profile = Profile::factory()->personal()->create(['concurrency_cap' => 2]);

    $p90 = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready, 'priority' => 90, 'ref' => 'DEMO-001']);
    $p50 = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready, 'priority' => 50, 'ref' => 'DEMO-002']);
    $p10 = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready, 'priority' => 10, 'ref' => 'DEMO-003']);

    $plan = scheduler()->tick(dryRun: true);

    expect($plan[0]['dispatched'])->toBe(['DEMO-001', 'DEMO-002'])
        ->and($p90->fresh()->status)->toBe(TaskStatus::Ready)
        ->and($p50->fresh()->status)->toBe(TaskStatus::Ready)
        ->and($p10->fresh()->status)->toBe(TaskStatus::Ready);

    Bus::assertNothingDispatched();
});

it('returns a structured plan per auto-dispatch profile', function () {
    Bus::fake();

    $profile = Profile::factory()->personal()->create(['concurrency_cap' => 3]);
    Task::factory()->for($profile)->create(['status' => TaskStatus::Processing]);
    Task::factory()->for($profile)->create(['status' => TaskStatus::Ready, 'priority' => 90]);

    $plan = scheduler()->tick();

    expect($plan)->toHaveCount(1)
        ->and($plan[0]['profile'])->toBe($profile->slug)
        ->and($plan[0]['cap'])->toBe(3)
        ->and($plan[0]['inflight'])->toBe(1)
        ->and($plan[0]['slots'])->toBe(2);
});
