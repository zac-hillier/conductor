<?php

use App\Enums\ProfileKind;
use App\Support\PolicyDefaults;

it('returns the personal rule set', function () {
    $rules = PolicyDefaults::for(ProfileKind::Personal);

    expect($rules['permission_mode'])->toBe('bypassPermissions')
        ->and($rules['disallowed_tools'])->toBe([])
        ->and($rules['require_review'])->toBeFalse();
});

it('returns the customer rule set', function () {
    $rules = PolicyDefaults::for(ProfileKind::Customer);

    expect($rules['permission_mode'])->toBe('acceptEdits')
        ->and($rules['require_review'])->toBeTrue()
        ->and($rules['disallowed_tools'])->toBe([
            'Bash(git commit:*)',
            'Bash(git push:*)',
            'Bash(git tag:*)',
            'Bash(git reset:*)',
        ]);
});
