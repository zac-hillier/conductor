<?php

namespace App\Support;

use App\Enums\ProfileKind;

class PolicyDefaults
{
    /**
     * Tools an automated worker must never run unattended. These are passed to
     * the worker as `--disallowedTools`; the human operator can still run them
     * directly on explicit command. Editable per profile in the settings panel.
     *
     * @var array<int, string>
     */
    public const RESTRICTED_TOOLS = [
        'Bash(git commit:*)',
        'Bash(git push:*)',
        'Bash(git tag:*)',
        'Bash(git reset:*)',
        'Bash(glab commit:*)',
        'Bash(glab push:*)',
        'Bash(glab tag:*)',
        'Bash(glab reset:*)',
        'Bash(terraform apply:*)',
        'Bash(php artisan migrate:fresh)',
    ];

    /**
     * Default capability rule set for a profile kind. Both kinds disallow the
     * RESTRICTED_TOOLS by default for automated dispatch; the difference is
     * permission mode / review / auto-dispatch.
     *
     * @return array{permission_mode: string, disallowed_tools: array<int, string>, require_review: bool, auto_dispatch: bool}
     */
    public static function for(ProfileKind $kind): array
    {
        return match ($kind) {
            ProfileKind::Personal => [
                'permission_mode' => 'bypassPermissions',
                'disallowed_tools' => self::RESTRICTED_TOOLS,
                'require_review' => false,
                'auto_dispatch' => true,
            ],
            ProfileKind::Customer => [
                'permission_mode' => 'acceptEdits',
                'disallowed_tools' => self::RESTRICTED_TOOLS,
                'require_review' => true,
                'auto_dispatch' => false,
            ],
        };
    }
}
