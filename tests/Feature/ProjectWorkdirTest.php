<?php

use App\Enums\TaskStatus;
use App\Jobs\RunTaskJob;
use App\Livewire\Board;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Task;
use App\Services\Claude\ClaudeRunner;
use App\Services\DispatchScheduler;
use App\Services\TaskPromptBuilder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FakeClaudeRunner;

it('resolves the project workdir over the profile workdir', function () {
    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $project = Project::factory()->for($profile)->create(['workdir' => '/tmp/conductor-work']);
    $task = Task::factory()->for($profile)->create(['project_id' => $project->id]);

    expect($task->resolvedWorkdir())->toBe('/tmp/conductor-work');
});

it('falls back to the profile workdir when the project has none', function () {
    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $project = Project::factory()->for($profile)->create(['workdir' => null]);
    $task = Task::factory()->for($profile)->create(['project_id' => $project->id]);

    expect($task->resolvedWorkdir())->toBe(sys_get_temp_dir());
});

it('runs a worker in the task project workdir, not the profile workdir', function () {
    Storage::fake('local');

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::success();
    app()->instance(ClaudeRunner::class, $fake);

    $profile = Profile::factory()->personal()->create(['workdir' => sys_get_temp_dir()]);
    $project = Project::factory()->for($profile)->create(['workdir' => '/tmp/conductor-work']);
    $task = Task::factory()->for($profile)->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Ready,
    ]);

    (new RunTaskJob($task))->handle($fake, app(TaskPromptBuilder::class));

    expect($fake->lastWorkdir)->toBe('/tmp/conductor-work');
});

it('keeps tasks isolated to their own project workdir', function () {
    Storage::fake('local');

    $profile = Profile::factory()->personal()->create(['workdir' => sys_get_temp_dir()]);
    $alpha = Project::factory()->for($profile)->create(['workdir' => '/tmp/conductor-work']);
    $beta = Project::factory()->for($profile)->create(['workdir' => '/tmp/conductor-scope']);

    $taskA = Task::factory()->for($profile)->create(['project_id' => $alpha->id, 'status' => TaskStatus::Ready]);
    $taskB = Task::factory()->for($profile)->create(['project_id' => $beta->id, 'status' => TaskStatus::Ready]);

    foreach ([[$taskA, '/tmp/conductor-work'], [$taskB, '/tmp/conductor-scope']] as [$task, $dir]) {
        $fake = new FakeClaudeRunner;
        $fake->result = FakeClaudeRunner::success();
        (new RunTaskJob($task))->handle($fake, app(TaskPromptBuilder::class));
        expect($fake->lastWorkdir)->toBe($dir);
    }
});

it('blocks a task whose project workdir is invalid even when the profile workdir is valid', function () {
    $fake = new FakeClaudeRunner;
    app()->instance(ClaudeRunner::class, $fake);

    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $project = Project::factory()->for($profile)->create(['workdir' => '/no/such/dir']);
    $task = Task::factory()->for($profile)->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Ready,
    ]);

    (new RunTaskJob($task))->handle($fake, app(TaskPromptBuilder::class));

    expect($task->fresh()->status)->toBe(TaskStatus::Blocked)
        ->and($fake->lastPrompt)->toBeNull();
});

it('does not auto-dispatch a task whose resolved workdir is invalid', function () {
    Bus::fake();
    config()->set('conductor.readiness.enabled', false);

    $profile = Profile::factory()->personal()->create(['workdir' => sys_get_temp_dir()]);
    $bad = Project::factory()->for($profile)->create(['workdir' => '/no/such/dir']);
    $good = Project::factory()->for($profile)->create(['workdir' => sys_get_temp_dir()]);

    $skipped = Task::factory()->for($profile)->create(['project_id' => $bad->id, 'status' => TaskStatus::Ready, 'priority' => 90]);
    $dispatched = Task::factory()->for($profile)->create(['project_id' => $good->id, 'status' => TaskStatus::Ready, 'priority' => 80]);

    app(DispatchScheduler::class)->tick();

    expect($skipped->fresh()->status)->toBe(TaskStatus::Ready)
        ->and($dispatched->fresh()->status)->toBe(TaskStatus::Processing);
    Bus::assertDispatchedTimes(RunTaskJob::class, 1);
});

it('assigns the default project when creating a task on the board', function () {
    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $default = Project::factory()->for($profile)->default()->create();

    Livewire::test(Board::class, ['profile' => $profile])
        ->set('title', 'A new task')
        ->set('priority', 50)
        ->call('createTask');

    $task = $profile->tasks()->firstOrFail();
    expect($task->project_id)->toBe($default->id);
});

it('includes the project name in the worker prompt', function () {
    $profile = Profile::factory()->create(['name' => 'Shore', 'workdir' => sys_get_temp_dir()]);
    $project = Project::factory()->for($profile)->create(['name' => 'mbridge']);
    $task = Task::factory()->for($profile)->create(['project_id' => $project->id, 'title' => 'Do it']);

    $prompt = (new TaskPromptBuilder)->build($task->load('profile', 'project'));

    expect($prompt)->toContain('Project: mbridge');
});
