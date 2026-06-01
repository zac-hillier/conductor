<?php

use App\Livewire\Settings;
use App\Models\Profile;
use Livewire\Livewire;

it('loads the current policy into the form', function () {
    $profile = Profile::factory()->customer()->create();

    Livewire::test(Settings::class, ['profile' => $profile])
        ->assertSet('permissionMode', 'acceptEdits')
        ->assertSet('requireReview', true)
        ->assertSee('Bash(git push:*)');
});

it('persists edited policy rules', function () {
    $profile = Profile::factory()->personal()->create();

    Livewire::test(Settings::class, ['profile' => $profile])
        ->set('permissionMode', 'acceptEdits')
        ->set('requireReview', true)
        ->set('disallowedTools', "Bash(git push:*)\nBash(rm:*)")
        ->call('save')
        ->assertSet('saved', true);

    $policy = $profile->fresh()->policy;

    expect($policy->permissionMode())->toBe('acceptEdits')
        ->and($policy->requiresReview())->toBeTrue()
        ->and($policy->disallowedTools())->toBe(['Bash(git push:*)', 'Bash(rm:*)']);
});
