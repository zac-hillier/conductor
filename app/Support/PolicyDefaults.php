<?php

namespace App\Support;

use App\Enums\ProfileKind;

class PolicyDefaults
{
    /**
     * Default capability rule set for a profile kind.
     *
     * @return array{permission_mode: string, disallowed_tools: array<int, string>, require_review: bool, auto_dispatch: bool}
     */
    public static function for(ProfileKind $kind): array
    {
        return match ($kind) {
            ProfileKind::Personal => [
                'permission_mode' => 'bypassPermissions',
                'disallowed_tools' => [],
                'require_review' => false,
                'auto_dispatch' => true,
            ],
            ProfileKind::Customer => [
                'permission_mode' => 'acceptEdits',
                'disallowed_tools' => [
                    'Bash(git commit:*)',
                    'Bash(git push:*)',
                    'Bash(git tag:*)',
                    'Bash(git reset:*)',
                ],
                'require_review' => true,
                'auto_dispatch' => false,
            ],
        };
    }
}
