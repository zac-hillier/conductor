<?php

use App\Enums\TaskStatus;
use App\Jobs\RunTaskJob;
use App\Livewire\Board;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Task;
use App\Services\Claude\ClaudeRunner;
use App\Services\TaskPromptBuilder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FakeClaudeRunner;

it('reassigns a task to another project in the same profile', function () {
    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $alpha = Project::factory()->for($profile)->create(['workdir' => '/tmp/conductor-work']);
    $beta = Project::factory()->for($profile)->create(['workdir' => '/tmp/conductor-scope']);
    $task = Task::factory()->for($profile)->create(['project_id' => $alpha->id, 'title' => 'x', 'priority' => 50]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->set('editProjectId', $beta->id)
        ->call('updateTask');

    $task->refresh();
    expect($task->project_id)->toBe($beta->id)
        ->and($task->resolvedWorkdir())->toBe('/tmp/conductor-scope');
});

it('refuses to reassign the project of a phase execution task', function () {
    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $project = Project::factory()->for($profile)->create(['workdir' => sys_get_temp_dir()]);
    $other = Project::factory()->for($profile)->create(['workdir' => sys_get_temp_dir()]);
    $plan = Plan::factory()->for($project)->create();
    $phase = Phase::factory()->for($plan)->create(['number' => 1]);
    $task = $phase->createBackingTask();

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->set('editProjectId', $other->id)
        ->call('updateTask')
        ->assertSet('actionNotice', fn ($v) => is_string($v) && str_contains($v, 'plan'));

    expect($task->fresh()->project_id)->toBe($project->id);
});

it('tags a task as relevant to a phase', function () {
    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $project = Project::factory()->for($profile)->create(['workdir' => sys_get_temp_dir()]);
    $plan = Plan::factory()->for($project)->create();
    $phase = Phase::factory()->for($plan)->create(['number' => 2, 'name' => 'Services']);
    $task = Task::factory()->for($profile)->create(['project_id' => $project->id, 'title' => 'x', 'priority' => 50]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->set('editPhaseId', $phase->id)
        ->call('updateTask');

    expect($task->fresh()->phase_id)->toBe($phase->id)
        ->and($task->fresh()->isPhaseExecutionTask())->toBeFalse();
});

it('runs a phase-relevant (non-execution) task as an ordinary task, not ag-exec', function () {
    Storage::fake('local');

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::success();
    app()->instance(ClaudeRunner::class, $fake);

    $profile = Profile::factory()->personal()->create(['workdir' => sys_get_temp_dir()]);
    $project = Project::factory()->for($profile)->create(['workdir' => sys_get_temp_dir()]);
    $plan = Plan::factory()->for($project)->create();
    $phase = Phase::factory()->for($plan)->create(['number' => 1]);

    // Tagged relevant (phase_id set) but NOT the phase's execution task.
    $task = Task::factory()->for($profile)->create([
        'project_id' => $project->id,
        'phase_id' => $phase->id,
        'status' => TaskStatus::Ready,
    ]);

    (new RunTaskJob($task))->handle($fake, app(TaskPromptBuilder::class));

    expect($fake->lastOptions)->not->toHaveKey('agent');
});
