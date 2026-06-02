<?php

use App\Enums\PhaseStatus;
use App\Enums\TaskStatus;
use App\Jobs\RunTaskJob;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Task;
use App\Services\Claude\ClaudeRunner;
use App\Services\TaskPromptBuilder;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeClaudeRunner;

function executablePhaseTask(): array
{
    $profile = Profile::factory()->personal()->create(['workdir' => sys_get_temp_dir()]);
    $project = Project::factory()->for($profile)->create(['workdir' => sys_get_temp_dir()]);
    $plan = Plan::factory()->for($project)->create(['artifact_dir' => sys_get_temp_dir().'/cc/x']);
    $phase = Phase::factory()->for($plan)->create([
        'number' => 1,
        'name' => 'Database foundation',
        'objective' => 'Schema + models',
    ]);
    $task = $phase->createBackingTask();

    return [$phase, $task];
}

it('executes a phase task as ag-exec with the pipeline timeout', function () {
    Storage::fake('local');

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::success();
    app()->instance(ClaudeRunner::class, $fake);

    [$phase, $task] = executablePhaseTask();

    (new RunTaskJob($task))->handle($fake, app(TaskPromptBuilder::class));

    expect($fake->lastOptions['agent'])->toBe('ag-exec')
        ->and($fake->lastOptions['timeout'])->toBe((int) config('conductor.pipeline.exec.timeout'))
        ->and($fake->lastPrompt)->toContain('Phase 1: Database foundation')
        ->and($fake->lastPrompt)->toContain('goal-contract');
});

it('syncs the phase to done when its task completes (personal auto-complete)', function () {
    Storage::fake('local');

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::success();
    app()->instance(ClaudeRunner::class, $fake);

    [$phase, $task] = executablePhaseTask();

    (new RunTaskJob($task))->handle($fake, app(TaskPromptBuilder::class));

    $task->refresh();
    $phase->refresh();
    expect($task->status)->toBe(TaskStatus::Complete)
        ->and($phase->status)->toBe(PhaseStatus::Done)
        ->and($phase->finished_at)->not->toBeNull()
        ->and((float) $phase->cost)->toBe(0.0421);
});

it('syncs the phase to blocked when its task fails', function () {
    Storage::fake('local');

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::error();
    app()->instance(ClaudeRunner::class, $fake);

    [$phase, $task] = executablePhaseTask();

    (new RunTaskJob($task))->handle($fake, app(TaskPromptBuilder::class));

    expect($task->fresh()->status)->toBe(TaskStatus::Blocked)
        ->and($phase->fresh()->status)->toBe(PhaseStatus::Blocked);
});

it('leaves an ordinary (non-phase) task on the standard path', function () {
    Storage::fake('local');

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::success();
    app()->instance(ClaudeRunner::class, $fake);

    $profile = Profile::factory()->personal()->create(['workdir' => sys_get_temp_dir()]);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready]);

    (new RunTaskJob($task))->handle($fake, app(TaskPromptBuilder::class));

    expect($fake->lastOptions)->not->toHaveKey('agent')
        ->and($task->fresh()->status)->toBe(TaskStatus::Complete);
});
