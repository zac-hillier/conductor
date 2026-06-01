<?php

use App\Services\Claude\ProcessClaudeRunner;

it('builds the expected command argv', function () {
    config()->set('conductor.claude.binary', 'claude');
    config()->set('conductor.claude.model', 'sonnet');
    config()->set('conductor.claude.permission_mode', 'acceptEdits');
    config()->set('conductor.claude.extra_args', []);

    $command = (new ProcessClaudeRunner)->buildCommand('do the thing');

    expect($command)->toBe([
        'claude',
        '-p', 'do the thing',
        '--output-format', 'json',
        '--permission-mode', 'acceptEdits',
        '--model', 'sonnet',
    ]);
});

it('appends configured extra args', function () {
    config()->set('conductor.claude.extra_args', ['--verbose']);

    $command = (new ProcessClaudeRunner)->buildCommand('go');

    expect($command)->toContain('--verbose');
});

it('encodes the transcript path from workdir and session id', function () {
    $path = ProcessClaudeRunner::transcriptPath('/home/zac/conductor.app', 'abc-123');

    expect($path)->toEndWith('/.claude/projects/-home-zac-conductor-app/abc-123.jsonl');
});
