<?php

use App\Enums\ProfileKind;
use App\Livewire\Overview;
use App\Models\Profile;
use Livewire\Livewire;

it('loads the overview route and shows the empty state with no profiles', function () {
    $response = $this->get(route('overview'));

    $response->assertOk();
    $response->assertSee('No profiles yet');
});

it('creates a profile via the Overview component and shows it on the page', function () {
    Livewire::test(Overview::class)
        ->set('name', 'Acme Industries')
        ->set('kind', ProfileKind::Customer->value)
        ->call('createProfile')
        ->assertHasNoErrors()
        ->assertSee('Acme Industries');

    $profile = Profile::firstWhere('name', 'Acme Industries');

    expect($profile)->not->toBeNull()
        ->and($profile->slug)->toBe('acme-industries')
        ->and($profile->kind)->toBe(ProfileKind::Customer);
});
