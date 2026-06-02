<?php

use App\Jobs\DiscoverProjectJob;
use App\Livewire\Projects;
use App\Models\Profile;
use App\Models\Project;
use App\Services\Claude\ClaudeRunner;
use Livewire\Livewire;
use Tests\Support\FakeClaudeRunner;

it('stores an agent-proposed role map for an odd-layout project', function () {
    $knowledge = base_path('tests/Fixtures/projects/odd/knowledge');
    $journal = base_path('tests/Fixtures/projects/odd/journal');

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::json([
        'reference' => [$knowledge],
        'docs' => [],
        'living_state' => [$journal],
        'entry_doc' => $journal.'/log.md',
        'rationale' => 'knowledge holds frozen ERP notes; journal is the running session log.',
    ]);
    app()->instance(ClaudeRunner::class, $fake);

    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $project = Project::factory()->for($profile)->create([
        'workdir' => base_path('tests/Fixtures/projects/odd'),
    ]);
    $project->discoverContext(); // heuristics find nothing

    expect($project->refresh()->context_map['roles']['reference'])->toBe([]);

    (new DiscoverProjectJob($project))->handle($fake);

    $proposal = $project->refresh()->settings['context_proposal'];
    expect($proposal['roles']['reference'])->toBe([$knowledge])
        ->and($proposal['roles']['living_state'])->toBe([$journal])
        ->and($proposal['entry_doc'])->toBe($journal.'/log.md')
        ->and($fake->lastOptions['allowed_tools'])->toBe(['Read', 'Grep', 'Glob']);
});

it('confirms a proposal into the context map and clears it', function () {
    $knowledge = base_path('tests/Fixtures/projects/odd/knowledge');

    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $project = Project::factory()->for($profile)->create([
        'workdir' => base_path('tests/Fixtures/projects/odd'),
        'settings' => ['context_proposal' => [
            'roles' => ['reference' => [$knowledge], 'docs' => [], 'living_state' => []],
            'entry_doc' => null,
            'rationale' => 'x',
        ]],
    ]);

    Livewire::test(Projects::class, ['profile' => $profile])
        ->call('confirmProposal', $project->id);

    $project->refresh();
    expect($project->context_map['roles']['reference'])->toBe([$knowledge])
        ->and($project->settings['context_proposal'] ?? null)->toBeNull();
});

it('discards a proposal without touching the context map', function () {
    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $project = Project::factory()->for($profile)->create([
        'settings' => ['context_proposal' => ['roles' => ['reference' => ['/x'], 'docs' => [], 'living_state' => []]]],
    ]);

    Livewire::test(Projects::class, ['profile' => $profile])
        ->call('discardProposal', $project->id);

    expect($project->refresh()->settings['context_proposal'] ?? null)->toBeNull()
        ->and($project->context_map)->toBeNull();
});
