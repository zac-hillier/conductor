<?php

use App\Models\Profile;
use App\Models\Project;
use App\Services\ProjectContextDiscovery;

function fixturePath(string $name): string
{
    return base_path('tests/Fixtures/projects/'.$name);
}

it('classifies reference, docs and living-state folders by name', function () {
    $map = (new ProjectContextDiscovery)->discover(fixturePath('wt-like'));

    $living = $map['roles']['living_state'];
    $docs = $map['roles']['docs'];
    $reference = $map['roles']['reference'];

    expect($living)->toContain(fixturePath('wt-like/cc/agent_docs'))
        ->and($docs)->toContain(fixturePath('wt-like/cc/docs'))
        ->and($reference)->toContain(fixturePath('wt-like/docs/reference'));
});

it('finds the manifest and parses its repos', function () {
    $map = (new ProjectContextDiscovery)->discover(fixturePath('wt-like'));

    expect($map['manifest'])->not->toBeNull()
        ->and($map['manifest']['project_name'])->toBe('returns')
        ->and($map['manifest']['repos'])->toHaveCount(2)
        ->and($map['manifest']['repos'][0]['name'])->toBe('returns-api');
});

it('finds the living-state entry doc', function () {
    $map = (new ProjectContextDiscovery)->discover(fixturePath('wt-like'));

    expect($map['entry_doc'])->toBe(fixturePath('wt-like/cc/agent_docs/INDEX.md'));
});

it('degrades gracefully for a bare project', function () {
    $map = (new ProjectContextDiscovery)->discover(fixturePath('bare'));

    expect($map['roles']['reference'])->toBe([])
        ->and($map['roles']['docs'])->toBe([])
        ->and($map['roles']['living_state'])->toBe([])
        ->and($map['manifest'])->toBeNull()
        ->and($map['entry_doc'])->toBeNull();
});

it('returns an empty map for a missing workdir', function () {
    $map = (new ProjectContextDiscovery)->discover('/no/such/dir');

    expect($map['roles']['living_state'])->toBe([])
        ->and($map['manifest'])->toBeNull();
});

it('persists the context map on the project', function () {
    $profile = Profile::factory()->create(['workdir' => fixturePath('wt-like')]);
    $project = Project::factory()->for($profile)->create(['workdir' => null]);

    $project->discoverContext();

    $project->refresh();
    expect($project->context_map)->not->toBeNull()
        ->and($project->context_map['entry_doc'])->toBe(fixturePath('wt-like/cc/agent_docs/INDEX.md'))
        ->and($project->context_map['roles']['reference'])->toContain(fixturePath('wt-like/docs/reference'));
});
