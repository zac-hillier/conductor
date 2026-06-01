<?php

use App\Enums\TaskStatus;
use App\Jobs\RunTaskJob;
use App\Models\Profile;
use App\Models\Task;
use Illuminate\Support\Facades\Bus;

it('prints the dry-run plan without claiming or dispatching', function () {
    Bus::fake();

    $profile = Profile::factory()->personal()->create(['concurrency_cap' => 2, 'slug' => 'demo']);
    $task = Task::factory()->for($profile)->create([
        'status' => TaskStatus::Ready,
        'priority' => 90,
        'ref' => 'DEMO-001',
    ]);

    $this->artisan('conductor:dispatch-tick', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->expectsOutputToContain('DEMO-001')
        ->assertSuccessful();

    expect($task->fresh()->status)->toBe(TaskStatus::Ready);
    Bus::assertNothingDispatched();
});

it('claims and dispatches when run for real', function () {
    Bus::fake();

    $profile = Profile::factory()->personal()->create(['concurrency_cap' => 1]);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready, 'priority' => 90]);

    $this->artisan('conductor:dispatch-tick')->assertSuccessful();

    expect($task->fresh()->status)->toBe(TaskStatus::Processing);
    Bus::assertDispatchedTimes(RunTaskJob::class, 1);
});
