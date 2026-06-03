<?php

use App\Services\Claude\ProcessClaudeRunner;
use Symfony\Component\Process\Process;

it('marks Conductor database credentials for removal from the worker env', function () {
    $env = (new ProcessClaudeRunner)->strippedEnv();

    foreach (['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
        expect($env)->toHaveKey($key)
            ->and($env[$key])->toBeFalse();
    }
});

it('does not leak DB_DATABASE into a spawned worker process', function () {
    // The parent (test) process has DB_DATABASE set by the framework bootstrap;
    // a child spawned with the stripped env must not see it.
    $env = (new ProcessClaudeRunner)->strippedEnv();

    $process = new Process(['printenv'], sys_get_temp_dir(), $env);
    $process->run();

    expect($process->getOutput())->not->toMatch('/^DB_DATABASE=/m')
        ->and($process->getOutput())->not->toMatch('/^DB_PORT=/m');
})->skip(trim((string) shell_exec('command -v printenv')) === '', 'printenv not available');
