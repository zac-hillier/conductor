<?php

use App\Enums\PhaseStatus;
use App\Enums\PlanStatus;
use App\Enums\TaskStatus;
use App\Jobs\ReviewPhaseJob;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Project;
use App\Services\Claude\ClaudeRunner;
use App\Services\PlanCoordinator;
use Tests\Support\FakeClaudeRunner;

function twoPhasePlan(bool $requireReview = false): Plan
{
    $profile = Profile::factory()->{$requireReview ? 'customer' : 'personal'}()->create(['workdir' => sys_get_temp_dir()]);
    $project = Project::factory()->for($profile)->create(['workdir' => sys_get_temp_dir()]);
    $plan = Plan::factory()->for($project)->create([
        'status' => PlanStatus::Ready,
        'artifact_dir' => sys_get_temp_dir().'/cc/x',
    ]);
    Phase::factory()->for($plan)->create(['number' => 1, 'name' => 'One']);
    Phase::factory()->for($plan)->create(['number' => 2, 'name' => 'Two']);

    return $plan;
}

beforeEach(function () {
    config()->set('conductor.pipeline.review.enabled', false);
});

it('advances to the next phase when a phase task completes', function () {
    $plan = twoPhasePlan();
    $one = $plan->phases()->where('number', 1)->firstOrFail();
    $task = $one->createBackingTask();

    // Simulate the worker completing the task (personal => auto-complete).
    $task->update(['status' => TaskStatus::Complete]);
    app(PlanCoordinator::class)->onTaskSettled($task->fresh());

    $plan->refresh();
    expect($one->refresh()->status)->toBe(PhaseStatus::Done)
        ->and($plan->status)->toBe(PlanStatus::Executing);

    $two = $plan->phases()->where('number', 2)->firstOrFail();
    expect($two->status)->toBe(PhaseStatus::Ready)
        ->and($two->task_id)->not->toBeNull();
});

it('completes the plan when the final phase is done', function () {
    $plan = twoPhasePlan();

    foreach ([1, 2] as $n) {
        $phase = $plan->phases()->where('number', $n)->firstOrFail();
        $task = $phase->task ?? $phase->createBackingTask();
        $task->update(['status' => TaskStatus::Complete]);
        app(PlanCoordinator::class)->onTaskSettled($task->fresh());
    }

    expect($plan->refresh()->status)->toBe(PlanStatus::Complete)
        ->and($plan->phases()->where('status', PhaseStatus::Done->value)->count())->toBe(2);
});

it('pauses at review and does not advance until the next phase is unblocked', function () {
    $plan = twoPhasePlan();
    $one = $plan->phases()->where('number', 1)->firstOrFail();
    $task = $one->createBackingTask();

    // require_review path: task lands in Review, not Complete.
    $task->update(['status' => TaskStatus::Review]);
    app(PlanCoordinator::class)->onTaskSettled($task->fresh());

    expect($one->refresh()->status)->toBe(PhaseStatus::Review);
    $two = $plan->phases()->where('number', 2)->firstOrFail();
    expect($two->status)->toBe(PhaseStatus::Pending)
        ->and($two->task_id)->toBeNull();
});

it('blocks the plan on a red ag-review verdict', function () {
    config()->set('conductor.pipeline.review.enabled', true);

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::json(['verdict' => 'red', 'blockers' => ['core path broken'], 'summary' => 'no']);
    app()->instance(ClaudeRunner::class, $fake);

    $plan = twoPhasePlan();
    $one = $plan->phases()->where('number', 1)->firstOrFail();
    $task = $one->createBackingTask();
    $task->update(['status' => TaskStatus::Complete]);

    // onTaskSettled dispatches ReviewPhaseJob (review enabled); run it directly.
    app(PlanCoordinator::class)->onTaskSettled($task->fresh());
    (new ReviewPhaseJob($one->fresh()))->handle($fake, app(PlanCoordinator::class));

    expect($one->refresh()->review_verdict)->toBe('red')
        ->and($one->status)->toBe(PhaseStatus::Blocked)
        ->and($plan->refresh()->status)->toBe(PlanStatus::Blocked)
        ->and($plan->phases()->where('number', 2)->firstOrFail()->task_id)->toBeNull();
});

it('advances on a green ag-review verdict', function () {
    config()->set('conductor.pipeline.review.enabled', true);

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::json(['verdict' => 'green', 'blockers' => [], 'summary' => 'ok']);
    app()->instance(ClaudeRunner::class, $fake);

    $plan = twoPhasePlan();
    $one = $plan->phases()->where('number', 1)->firstOrFail();
    $task = $one->createBackingTask();
    $task->update(['status' => TaskStatus::Complete]);

    app(PlanCoordinator::class)->onTaskSettled($task->fresh());
    (new ReviewPhaseJob($one->fresh()))->handle($fake, app(PlanCoordinator::class));

    expect($one->refresh()->review_verdict)->toBe('green')
        ->and($plan->refresh()->phases()->where('number', 2)->firstOrFail()->task_id)->not->toBeNull();
});
