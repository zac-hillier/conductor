<?php

return [
    'claude' => [
        'binary' => env('CONDUCTOR_CLAUDE_BINARY', 'claude'),
        'model' => env('CONDUCTOR_CLAUDE_MODEL', 'sonnet'),
        'permission_mode' => env('CONDUCTOR_CLAUDE_PERMISSION_MODE', 'acceptEdits'),
        'timeout' => (int) env('CONDUCTOR_CLAUDE_TIMEOUT', 600),
        'extra_args' => [],
    ],

    'notifications' => [
        'enabled' => (bool) env('CONDUCTOR_NOTIFICATIONS_ENABLED', true),
        'events' => ['needs_input', 'blocked', 'review', 'complete', 'run_failed'],
    ],

    'recovery' => [
        'grace' => (int) env('CONDUCTOR_RECOVERY_GRACE', 120),
    ],

    // Multi-phase orchestration pipeline (WS-2C). Conductor spawns each agent as
    // a discrete unit of cognition; exec runs are long, hence per-step timeouts.
    'pipeline' => [
        'scout' => [
            'agent' => env('CONDUCTOR_PIPELINE_SCOUT_AGENT', 'ag-scout'),
            'enabled' => (bool) env('CONDUCTOR_PIPELINE_SCOUT_ENABLED', false),
            'timeout' => (int) env('CONDUCTOR_PIPELINE_SCOUT_TIMEOUT', 900),
        ],
        'phase' => [
            'agent' => env('CONDUCTOR_PIPELINE_PHASE_AGENT', 'ag-phase'),
            'model' => env('CONDUCTOR_PIPELINE_PHASE_MODEL', 'opus'),
            'timeout' => (int) env('CONDUCTOR_PIPELINE_PHASE_TIMEOUT', 1200),
        ],
        'prompter' => [
            'agent' => env('CONDUCTOR_PIPELINE_PROMPTER_AGENT', 'ag-prompter'),
            'timeout' => (int) env('CONDUCTOR_PIPELINE_PROMPTER_TIMEOUT', 600),
        ],
        'exec' => [
            'agent' => env('CONDUCTOR_PIPELINE_EXEC_AGENT', 'ag-exec'),
            'timeout' => (int) env('CONDUCTOR_PIPELINE_EXEC_TIMEOUT', 3600),
        ],
        'review' => [
            'agent' => env('CONDUCTOR_PIPELINE_REVIEW_AGENT', 'ag-review'),
            // When on, each finished phase gets an automated ag-review gate; a
            // red verdict blocks the plan. Off by default — the profile policy's
            // require_review human gate is the baseline.
            'enabled' => (bool) env('CONDUCTOR_PIPELINE_REVIEW_ENABLED', false),
            'timeout' => (int) env('CONDUCTOR_PIPELINE_REVIEW_TIMEOUT', 900),
        ],
    ],

    'readiness' => [
        'enabled' => (bool) env('CONDUCTOR_READINESS_ENABLED', true),
        'reviewers' => array_filter(array_map(
            'trim',
            explode(',', (string) env('CONDUCTOR_READINESS_REVIEWERS', 'ag-review,ag-gap-analysis,ag-devops-review')),
        )),
        'green' => (int) env('CONDUCTOR_READINESS_GREEN', 80),
        'amber' => (int) env('CONDUCTOR_READINESS_AMBER', 50),
        'min_auto_dispatch' => (int) env('CONDUCTOR_READINESS_MIN_AUTO_DISPATCH', 50),
    ],
];
