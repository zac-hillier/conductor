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

it('includes permission mode and disallowed tools when options are set', function () {
    $runner = new ProcessClaudeRunner;

    $command = $runner->buildCommand('go', [
        'permission_mode' => 'acceptEdits',
        'disallowed_tools' => ['Bash(git commit:*)', 'Bash(git push:*)'],
    ]);

    expect($command)->toContain('--permission-mode')
        ->toContain('acceptEdits')
        ->toContain('--disallowedTools')
        ->toContain('Bash(git commit:*)')
        ->toContain('Bash(git push:*)');

    $string = $runner->commandString('go', [
        'permission_mode' => 'acceptEdits',
        'disallowed_tools' => ['Bash(git commit:*)', 'Bash(git push:*)'],
    ]);

    expect($string)->toContain('--permission-mode')
        ->toContain('--disallowedTools');
});

it('appends allowed tools for a read-only scoping run and omits edit/bash tools', function () {
    $runner = new ProcessClaudeRunner;

    $command = $runner->buildCommand('scope this', [
        'allowed_tools' => ['Read', 'Grep', 'Glob'],
    ]);

    expect($command)->toContain('--allowedTools')
        ->toContain('Read')
        ->toContain('Grep')
        ->toContain('Glob')
        ->toContain('--output-format')
        ->not->toContain('--disallowedTools')
        ->not->toContain('Edit')
        ->not->toContain('Write')
        ->not->toContain('Bash');

    $string = $runner->commandString('scope this', [
        'allowed_tools' => ['Read', 'Grep', 'Glob'],
    ]);

    expect($string)->toContain('--allowedTools')
        ->toContain('--output-format');
});

it('builds an agent flag and read-only tools for a readiness reviewer run', function () {
    $runner = new ProcessClaudeRunner;

    $command = $runner->buildCommand('score this', [
        'agent' => 'ag-review',
        'allowed_tools' => ['Read', 'Grep', 'Glob'],
    ]);

    expect($command)->toContain('--agent')
        ->toContain('ag-review')
        ->toContain('--output-format')
        ->toContain('--allowedTools')
        ->toContain('Read')
        ->toContain('Grep')
        ->toContain('Glob')
        ->not->toContain('--disallowedTools');

    // --agent must precede the prompt-following flags, after the model.
    $agentIndex = array_search('--agent', $command, true);
    expect($command[$agentIndex + 1])->toBe('ag-review');

    $string = $runner->commandString('score this', [
        'agent' => 'ag-review',
        'allowed_tools' => ['Read', 'Grep', 'Glob'],
    ]);

    expect($string)->toContain('--agent')
        ->toContain('ag-review')
        ->toContain('--allowedTools');
});

it('omits the agent flag when no agent is set', function () {
    $command = (new ProcessClaudeRunner)->buildCommand('go');

    expect($command)->not->toContain('--agent');
});

it('omits the allowed tools flag when the list is empty', function () {
    $command = (new ProcessClaudeRunner)->buildCommand('go', [
        'allowed_tools' => [],
    ]);

    expect($command)->not->toContain('--allowedTools');
});

it('omits the disallowed tools flag when the list is empty', function () {
    $command = (new ProcessClaudeRunner)->buildCommand('go', [
        'permission_mode' => 'bypassPermissions',
        'disallowed_tools' => [],
    ]);

    expect($command)->toContain('--permission-mode')
        ->toContain('bypassPermissions')
        ->not->toContain('--disallowedTools');
});

it('encodes the transcript path from workdir and session id', function () {
    $path = ProcessClaudeRunner::transcriptPath('/home/zac/conductor.app', 'abc-123');

    expect($path)->toEndWith('/.claude/projects/-home-zac-conductor-app/abc-123.jsonl');
});
