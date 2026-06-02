<?php

use App\Enums\PlanStatus;
use App\Jobs\GeneratePlanJob;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Project;
use App\Services\Claude\ClaudeRunner;
use App\Services\PlanPromptBuilder;
use Tests\Support\FakeClaudeRunner;

function draftingPlan(): Plan
{
    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $project = Project::factory()->for($profile)->create(['workdir' => sys_get_temp_dir()]);

    return Plan::factory()->for($project)->create([
        'status' => PlanStatus::Drafting,
        'slug' => 'returns-filter',
        'concept' => 'Add a returns reason filter.',
    ]);
}

it('generates phases from the ag-phase manifest and marks the plan ready', function () {
    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::json([
        'summary' => 'A three-phase build.',
        'phases' => [
            ['number' => 1, 'name' => 'Database foundation', 'objective' => 'Schema + models', 'gateway_test' => 'tests/Feature/Phase1GatewayTest.php', 'exit_criteria' => ['migrations run', 'models exist']],
            ['number' => 2, 'name' => 'Service layer', 'objective' => 'Services', 'exit_criteria' => ['services tested']],
            ['number' => 3, 'name' => 'Views', 'objective' => 'Blade', 'exit_criteria' => ['UI renders']],
        ],
    ]);
    app()->instance(ClaudeRunner::class, $fake);

    $plan = draftingPlan();

    (new GeneratePlanJob($plan))->handle($fake, app(PlanPromptBuilder::class));

    $plan->refresh();
    expect($plan->status)->toBe(PlanStatus::Ready)
        ->and($plan->summary)->toBe('A three-phase build.')
        ->and($plan->artifact_dir)->toContain('/cc/')
        ->and($plan->artifact_dir)->toContain('returns-filter')
        ->and($plan->phases()->count())->toBe(3);

    $phase1 = $plan->phases()->where('number', 1)->firstOrFail();
    expect($phase1->name)->toBe('Database foundation')
        ->and($phase1->gateway_test)->toBe('tests/Feature/Phase1GatewayTest.php')
        ->and($phase1->exit_criteria)->toBe(['migrations run', 'models exist']);

    // The phase step ran as ag-phase.
    expect($fake->lastOptions['agent'])->toBe('ag-phase');
});

it('blocks the plan when the planning agent returns no phases', function () {
    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::text('I could not produce a plan.');
    app()->instance(ClaudeRunner::class, $fake);

    $plan = draftingPlan();

    (new GeneratePlanJob($plan))->handle($fake, app(PlanPromptBuilder::class));

    expect($plan->refresh()->status)->toBe(PlanStatus::Blocked)
        ->and($plan->phases()->count())->toBe(0);
});

it('is a no-op for a plan that is not drafting', function () {
    $fake = new FakeClaudeRunner;
    app()->instance(ClaudeRunner::class, $fake);

    $plan = draftingPlan();
    $plan->update(['status' => PlanStatus::Ready]);

    (new GeneratePlanJob($plan))->handle($fake, app(PlanPromptBuilder::class));

    expect($plan->refresh()->phases()->count())->toBe(0)
        ->and($fake->lastPrompt)->toBeNull();
});
