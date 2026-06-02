<?php

use App\Enums\PhaseStatus;
use App\Enums\PlanStatus;
use App\Enums\TaskStatus;
use App\Jobs\GeneratePlanJob;
use App\Livewire\Board;
use App\Livewire\PlanDetail;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

it('promotes a task into a plan and dispatches the planning pipeline', function () {
    Bus::fake();

    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $project = Project::factory()->for($profile)->default()->create();
    $task = Task::factory()->for($profile)->create([
        'project_id' => $project->id,
        'title' => 'Build a returns system',
        'summary' => 'Customers request returns online.',
        'status' => TaskStatus::Ready,
    ]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->call('promoteToPlan');

    $plan = Plan::firstOrFail();
    expect($plan->source_task_id)->toBe($task->id)
        ->and($plan->name)->toBe('Build a returns system')
        ->and($plan->concept)->toContain('Customers request returns online.')
        ->and($plan->project_id)->toBe($project->id);

    Bus::assertDispatched(GeneratePlanJob::class, fn ($job) => $job->plan->is($plan));
});

it('retries a blocked phase with a fresh task', function () {
    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $project = Project::factory()->for($profile)->create(['workdir' => sys_get_temp_dir()]);
    $plan = Plan::factory()->for($project)->create(['status' => PlanStatus::Blocked]);
    $phase = Phase::factory()->for($plan)->create(['number' => 1, 'status' => PhaseStatus::Blocked, 'review_verdict' => 'red']);

    Livewire::test(PlanDetail::class, ['profile' => $profile, 'plan' => $plan])
        ->call('retryPhase', $phase->id);

    $phase->refresh();
    expect($phase->status)->toBe(PhaseStatus::Ready)
        ->and($phase->review_verdict)->toBeNull()
        ->and($phase->task_id)->not->toBeNull()
        ->and($plan->refresh()->status)->toBe(PlanStatus::Executing);
});

it('deletes a plan and keeps its backing tasks', function () {
    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $project = Project::factory()->for($profile)->create(['workdir' => sys_get_temp_dir()]);
    $plan = Plan::factory()->for($project)->create();
    $phase = Phase::factory()->for($plan)->create(['number' => 1]);
    $task = $phase->createBackingTask();

    Livewire::test(PlanDetail::class, ['profile' => $profile, 'plan' => $plan])
        ->call('deletePlan');

    expect(Plan::find($plan->id))->toBeNull()
        ->and(Phase::find($phase->id))->toBeNull()
        ->and(Task::find($task->id))->not->toBeNull()
        ->and(Task::find($task->id)->phase_id)->toBeNull();
});

it('renders the plan dashboard with phase progress', function () {
    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $project = Project::factory()->for($profile)->create(['workdir' => sys_get_temp_dir()]);
    $plan = Plan::factory()->for($project)->create(['name' => 'My Plan', 'status' => PlanStatus::Executing]);
    Phase::factory()->for($plan)->create(['number' => 1, 'status' => PhaseStatus::Done]);
    Phase::factory()->for($plan)->create(['number' => 2, 'status' => PhaseStatus::Pending]);

    Livewire::test(PlanDetail::class, ['profile' => $profile, 'plan' => $plan])
        ->assertSee('My Plan')
        ->assertSee('1 / 2');
});
