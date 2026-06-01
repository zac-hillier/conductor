<?php

return [
    'claude' => [
        'binary' => env('CONDUCTOR_CLAUDE_BINARY', 'claude'),
        'model' => env('CONDUCTOR_CLAUDE_MODEL', 'sonnet'),
        'permission_mode' => env('CONDUCTOR_CLAUDE_PERMISSION_MODE', 'acceptEdits'),
        'timeout' => (int) env('CONDUCTOR_CLAUDE_TIMEOUT', 600),
        'extra_args' => [],
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
