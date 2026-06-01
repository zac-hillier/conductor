<?php

return [
    'claude' => [
        'binary' => env('CONDUCTOR_CLAUDE_BINARY', 'claude'),
        'model' => env('CONDUCTOR_CLAUDE_MODEL', 'sonnet'),
        'permission_mode' => env('CONDUCTOR_CLAUDE_PERMISSION_MODE', 'acceptEdits'),
        'timeout' => (int) env('CONDUCTOR_CLAUDE_TIMEOUT', 600),
        'extra_args' => [],
    ],
];
