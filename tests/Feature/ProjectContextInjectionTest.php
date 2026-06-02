<?php

use App\Livewire\Board;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Task;
use App\Services\TaskPromptBuilder;
use Livewire\Livewire;

function wtLikeProject(): Project
{
    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $project = Project::factory()->for($profile)->create([
        'workdir' => base_path('tests/Fixtures/projects/wt-like'),
    ]);
    $project->discoverContext();

    return $project->refresh();
}

it('injects curated living-state into the worker prompt', function () {
    $project = wtLikeProject();
    $task = Task::factory()->for($project->profile)->create(['project_id' => $project->id, 'title' => 'X']);

    $prompt = (new TaskPromptBuilder)->build($task->load('profile', 'project'));

    expect($prompt)
        ->toContain('Navigation for anyone')   // INDEX entry doc body
        ->toContain('B-37')                     // open P0 blocker
        ->toContain('Phase 6 built');           // latest CHANGELOG entry
});

it('indexes reference docs without injecting their bodies, and marks edit policy', function () {
    $project = wtLikeProject();
    $task = Task::factory()->for($project->profile)->create(['project_id' => $project->id, 'title' => 'X']);

    $prompt = (new TaskPromptBuilder)->build($task->load('profile', 'project'));

    expect($prompt)
        ->toContain('do NOT edit')                  // reference policy
        ->toContain('propose an edit')              // docs policy
        ->toContain('elucid.md')                    // reference indexed by path
        ->not->toContain('Frozen ground truth');    // reference body NOT injected
});

it('injects a pinned reference doc body in full', function () {
    $project = wtLikeProject();
    $referencePath = base_path('tests/Fixtures/projects/wt-like/docs/reference/elucid.md');
    $task = Task::factory()->for($project->profile)->create([
        'project_id' => $project->id,
        'title' => 'X',
        'pinned_docs' => [$referencePath],
    ]);

    $prompt = (new TaskPromptBuilder)->build($task->load('profile', 'project'));

    expect($prompt)->toContain('Frozen ground truth'); // pinned body now present
});

it('falls back to the README instruction for a project with no discovered docs', function () {
    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $project = Project::factory()->for($profile)->create([
        'workdir' => base_path('tests/Fixtures/projects/bare'),
    ]);
    $project->discoverContext();
    $task = Task::factory()->for($profile)->create(['project_id' => $project->refresh()->id, 'title' => 'X']);

    $prompt = (new TaskPromptBuilder)->build($task->load('profile', 'project'));

    expect($prompt)
        ->toContain('README.md')
        ->not->toContain('Open P0 blockers');
});

it('pins and unpins a context doc from the drawer', function () {
    $project = wtLikeProject();
    $referencePath = base_path('tests/Fixtures/projects/wt-like/docs/reference/elucid.md');
    $task = Task::factory()->for($project->profile)->create(['project_id' => $project->id]);

    Livewire::test(Board::class, ['profile' => $project->profile])
        ->call('selectTask', $task->id)
        ->call('togglePin', $referencePath);

    expect($task->fresh()->pinned_docs)->toBe([$referencePath]);

    Livewire::test(Board::class, ['profile' => $project->profile])
        ->call('selectTask', $task->id)
        ->call('togglePin', $referencePath);

    expect($task->fresh()->pinned_docs)->toBe([]);
});
