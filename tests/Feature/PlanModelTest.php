<?php

use App\Enums\PhaseStatus;
use App\Enums\PlanStatus;
use App\Enums\TaskStatus;
use App\Livewire\Board;
use App\Livewire\PlanDetail;
use App\Livewire\Plans;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Task;
use Livewire\Livewire;

function planWithProject(): Plan
{
    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $project = Project::factory()->for($profile)->create(['workdir' => sys_get_temp_dir()]);

    return Plan::factory()->for($project)->create();
}

it('creates a plan with phases and reports the current phase', function () {
    $plan = planWithProject();
    Phase::factory()->for($plan)->create(['number' => 1, 'status' => PhaseStatus::Done]);
    $two = Phase::factory()->for($plan)->create(['number' => 2, 'status' => PhaseStatus::Pending]);
    Phase::factory()->for($plan)->create(['number' => 3, 'status' => PhaseStatus::Pending]);

    expect($plan->phases()->count())->toBe(3)
        ->and($plan->currentPhase()->id)->toBe($two->id);
});

it('only lets a phase become executable once its predecessors are done', function () {
    $plan = planWithProject();
    $one = Phase::factory()->for($plan)->create(['number' => 1, 'status' => PhaseStatus::Pending]);
    $two = Phase::factory()->for($plan)->create(['number' => 2, 'status' => PhaseStatus::Pending]);

    expect($one->isExecutable())->toBeTrue()
        ->and($two->isExecutable())->toBeFalse();

    $one->update(['status' => PhaseStatus::Done]);
    expect($two->refresh()->isExecutable())->toBeTrue();
});

it('creates a backing board task for a phase', function () {
    $plan = planWithProject();
    $phase = Phase::factory()->for($plan)->create(['number' => 1, 'name' => 'Database foundation']);

    $task = $phase->createBackingTask();

    expect($task->phase_id)->toBe($phase->id)
        ->and($task->project_id)->toBe($plan->project_id)
        ->and($task->status)->toBe(TaskStatus::Ready)
        ->and($task->title)->toBe('Phase 1: Database foundation')
        ->and($phase->refresh()->status)->toBe(PhaseStatus::Ready)
        ->and($phase->task_id)->toBe($task->id);
});

it('rolls phase costs up to the plan', function () {
    $plan = planWithProject();
    Phase::factory()->for($plan)->create(['number' => 1, 'cost' => 0.10]);
    Phase::factory()->for($plan)->create(['number' => 2, 'cost' => 0.25]);

    expect($plan->costRollup())->toBe(0.35);
});

it('creates a plan from the Plans page', function () {
    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $project = Project::factory()->for($profile)->default()->create();

    Livewire::test(Plans::class, ['profile' => $profile])
        ->set('name', 'Returns filter')
        ->set('concept', 'Add a reason filter to the returns admin.')
        ->set('projectId', $project->id)
        ->call('createPlan');

    $plan = Plan::firstOrFail();
    expect($plan->name)->toBe('Returns filter')
        ->and($plan->project_id)->toBe($project->id)
        ->and($plan->status)->toBe(PlanStatus::Drafting);
});

it('adds a phase and starts it from the plan detail page', function () {
    $plan = planWithProject();

    $component = Livewire::test(PlanDetail::class, ['profile' => $plan->project->profile, 'plan' => $plan])
        ->set('phaseName', 'Service layer')
        ->set('phaseObjective', 'Build the services.')
        ->call('addPhase');

    $phase = $plan->phases()->firstOrFail();
    expect($phase->number)->toBe(1)
        ->and($phase->name)->toBe('Service layer');

    $component->call('makeExecutable', $phase->id);

    expect($phase->refresh()->task_id)->not->toBeNull()
        ->and(Task::where('phase_id', $phase->id)->exists())->toBeTrue();
});

it('shows phase tasks on the board with a plan badge', function () {
    $plan = planWithProject();
    $plan->update(['name' => 'My Plan']);
    $phase = Phase::factory()->for($plan)->create(['number' => 2, 'name' => 'Services']);
    $task = $phase->createBackingTask();

    Livewire::test(Board::class, ['profile' => $plan->project->profile])
        ->assertSee('My Plan')
        ->assertSee('P2');
});
