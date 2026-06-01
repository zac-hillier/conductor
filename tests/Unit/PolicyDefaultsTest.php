<?php

use App\Enums\ProfileKind;
use App\Support\PolicyDefaults;

it('returns the personal rule set', function () {
    $rules = PolicyDefaults::for(ProfileKind::Personal);

    expect($rules['permission_mode'])->toBe('bypassPermissions')
        ->and($rules['disallowed_tools'])->toBe(PolicyDefaults::RESTRICTED_TOOLS)
        ->and($rules['require_review'])->toBeFalse()
        ->and($rules['auto_dispatch'])->toBeTrue();
});

it('returns the customer rule set', function () {
    $rules = PolicyDefaults::for(ProfileKind::Customer);

    expect($rules['permission_mode'])->toBe('acceptEdits')
        ->and($rules['require_review'])->toBeTrue()
        ->and($rules['auto_dispatch'])->toBeFalse()
        ->and($rules['disallowed_tools'])->toBe(PolicyDefaults::RESTRICTED_TOOLS);
});

it('restricts git, glab, terraform apply and migrate:fresh for automated workers by default', function () {
    expect(PolicyDefaults::RESTRICTED_TOOLS)
        ->toContain('Bash(git push:*)')
        ->toContain('Bash(glab push:*)')
        ->toContain('Bash(terraform apply:*)')
        ->toContain('Bash(php artisan migrate:fresh)');
});
