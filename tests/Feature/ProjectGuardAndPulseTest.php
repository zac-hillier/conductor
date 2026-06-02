<?php

use App\Enums\TaskStatus;
use App\Jobs\RunTaskJob;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Task;
use App\Services\Claude\ClaudeRunner;
use App\Services\TaskPromptBuilder;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeClaudeRunner;

function discoveredWtProject(): Project
{
    $profile = Profile::factory()->personal()->create(['workdir' => sys_get_temp_dir()]);
    $project = Project::factory()->for($profile)->create([
        'workdir' => base_path('tests/Fixtures/projects/wt-like'),
    ]);
    $project->discoverContext();

    return $project->refresh();
}

it('merges reference write-guard patterns into the worker disallowed tools', function () {
    Storage::fake('local');

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::success();
    app()->instance(ClaudeRunner::class, $fake);

    $project = discoveredWtProject();
    $task = Task::factory()->for($project->profile)->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Ready,
    ]);

    (new RunTaskJob($task))->handle($fake, app(TaskPromptBuilder::class));

    $referenceDir = base_path('tests/Fixtures/projects/wt-like/docs/reference');
    expect($fake->lastOptions['disallowed_tools'])
        ->toContain('Edit('.$referenceDir.'/**)')
        ->toContain('Write('.$referenceDir.'/**)');
});

it('exposes a project pulse from the living docs', function () {
    $project = discoveredWtProject();

    $pulse = $project->pulse();

    expect($pulse['open_p0'])->toBe(1)
        ->and($pulse['changelog'])->toContain('Phase 6 built')
        ->and($pulse['roadmap'])->toContain('IN PROGRESS');
});

it('has no guards and a flat pulse for a project with no reference docs', function () {
    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $project = Project::factory()->for($profile)->create([
        'workdir' => base_path('tests/Fixtures/projects/bare'),
    ]);
    $project->discoverContext();
    $task = Task::factory()->for($profile)->create(['project_id' => $project->refresh()->id]);

    expect($task->load('project')->referenceGuards())->toBe([])
        ->and($project->pulse())->toBe(['changelog' => null, 'open_p0' => 0, 'roadmap' => null]);
});
