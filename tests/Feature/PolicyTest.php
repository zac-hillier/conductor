<?php

use App\Enums\ProfileKind;
use App\Livewire\Overview;
use App\Models\Profile;

it('seeds a policy of the right kind when a profile is created', function () {
    $personal = Profile::factory()->personal()->create();
    $customer = Profile::factory()->customer()->create();

    expect($personal->policy)->not->toBeNull()
        ->and($personal->policy->permissionMode())->toBe('bypassPermissions')
        ->and($personal->policy->requiresReview())->toBeFalse()
        ->and($personal->policy->disallowedTools())->toBe([]);

    expect($customer->policy)->not->toBeNull()
        ->and($customer->policy->permissionMode())->toBe('acceptEdits')
        ->and($customer->policy->requiresReview())->toBeTrue()
        ->and($customer->policy->disallowedTools())->toContain('Bash(git push:*)');
});

it('falls back to a default policy built from kind when none persisted', function () {
    $profile = Profile::factory()->personal()->create();
    $profile->policy()->delete();
    $profile->refresh()->unsetRelation('policy');

    $policy = $profile->policyOrDefault();

    expect($policy->exists)->toBeFalse()
        ->and($policy->permissionMode())->toBe('bypassPermissions');
});

it('seeds the matching policy via the overview create flow', function () {
    Livewire\Livewire::test(Overview::class)
        ->set('name', 'Acme Co')
        ->set('kind', ProfileKind::Customer->value)
        ->call('createProfile');

    $profile = Profile::where('name', 'Acme Co')->firstOrFail();

    expect($profile->policy)->not->toBeNull()
        ->and($profile->policy->requiresReview())->toBeTrue();
});
