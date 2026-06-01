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

it('loads auto_dispatch and concurrency cap into the form', function () {
    $profile = Profile::factory()->personal()->create(['concurrency_cap' => 5]);

    Livewire::test(Settings::class, ['profile' => $profile])
        ->assertSet('autoDispatch', true)
        ->assertSet('concurrencyCap', 5);
});

it('persists the auto_dispatch toggle and concurrency cap', function () {
    $profile = Profile::factory()->customer()->create(['concurrency_cap' => 3]);

    Livewire::test(Settings::class, ['profile' => $profile])
        ->assertSet('autoDispatch', false)
        ->set('autoDispatch', true)
        ->set('concurrencyCap', 7)
        ->call('save')
        ->assertSet('saved', true);

    $fresh = $profile->fresh();

    expect($fresh->policy->autoDispatch())->toBeTrue()
        ->and($fresh->concurrency_cap)->toBe(7);
});
