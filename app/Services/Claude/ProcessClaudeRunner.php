<?php

namespace App\Services\Claude;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

class ProcessClaudeRunner implements ClaudeRunner
{
    public function run(string $prompt, string $workdir, array $options = []): ClaudeResult
    {
        $command = $this->buildCommand($prompt, $options);

        // CRITICAL: strip Conductor's own configuration from the worker's
        // environment. Laravel putenv's Conductor's .env (DB_*, REDIS_*, …) into
        // the real process env, which a child would otherwise inherit — so a
        // worker running `php artisan` in a TARGET project would read Conductor's
        // DB credentials and operate on Conductor's own database instead of the
        // project's. Setting each key to false removes it from the child env
        // (PATH/HOME/etc. are still inherited), so the target uses its own .env.
        $process = new Process($command, $workdir, $this->strippedEnv());
        $process->setTimeout((float) ($options['timeout'] ?? config('conductor.claude.timeout')));

        try {
            $process->run();
        } catch (ExceptionInterface $e) {
            return new ClaudeResult(
                isError: true,
                result: $e->getMessage(),
                rawJson: '',
            );
        }

        $output = $process->getOutput();

        if (! $process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());

            return new ClaudeResult(
                isError: true,
                result: $stderr !== '' ? $stderr : trim($output),
                rawJson: $output,
            );
        }

        return $this->parse($output);
    }

    /**
     * Conductor's own .env keys mapped to false, so Symfony Process removes them
     * from the spawned worker's inherited environment. The worker (and anything
     * it runs, e.g. `php artisan` in the target project) then falls back to that
     * project's own configuration rather than Conductor's.
     *
     * @return array<string, false>
     */
    public function strippedEnv(): array
    {
        $keys = [];

        $path = base_path('.env');
        if (is_file($path) && is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = ltrim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (preg_match('/^([A-Z0-9_]+)\s*=/', $line, $m) === 1) {
                    $keys[] = $m[1];
                }
            }
        }

        // Always strip the dangerous families even if .env can't be read.
        $keys = array_merge($keys, [
            'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME',
            'DB_PASSWORD', 'DB_URL', 'DATABASE_URL', 'REDIS_HOST', 'REDIS_PORT',
            'REDIS_PASSWORD', 'REDIS_URL', 'APP_KEY', 'APP_ENV',
        ]);

        return array_fill_keys(array_unique($keys), false);
    }

    /**
     * Build the argv array passed to the process.
     *
     * @param  array<string, mixed>  $options
     * @return array<int, string>
     */
    public function buildCommand(string $prompt, array $options = []): array
    {
        $binary = (string) config('conductor.claude.binary');
        $model = (string) ($options['model'] ?? config('conductor.claude.model'));
        $permissionMode = (string) ($options['permission_mode'] ?? config('conductor.claude.permission_mode'));

        /** @var array<int, string> $extra */
        $extra = $options['extra_args'] ?? config('conductor.claude.extra_args', []);

        /** @var array<int, string> $disallowed */
        $disallowed = $options['disallowed_tools'] ?? [];

        /** @var array<int, string> $allowed */
        $allowed = $options['allowed_tools'] ?? [];

        $agent = isset($options['agent']) ? (string) $options['agent'] : null;

        $command = [
            $binary,
            '-p', $prompt,
            '--output-format', 'json',
            '--permission-mode', $permissionMode,
            '--model', $model,
        ];

        if ($agent !== null && $agent !== '') {
            $command[] = '--agent';
            $command[] = $agent;
        }

        if ($allowed !== []) {
            $command[] = '--allowedTools';
            foreach (array_values($allowed) as $pattern) {
                $command[] = (string) $pattern;
            }
        }

        if ($disallowed !== []) {
            $command[] = '--disallowedTools';
            foreach (array_values($disallowed) as $pattern) {
                $command[] = (string) $pattern;
            }
        }

        return array_merge($command, array_values($extra));
    }

    /**
     * Render the built command as a copy-pasteable shell string (for inspection).
     *
     * @param  array<string, mixed>  $options
     */
    public function commandString(string $prompt, array $options = []): string
    {
        return implode(' ', array_map(
            fn (string $part) => escapeshellarg($part),
            $this->buildCommand($prompt, $options),
        ));
    }

    /**
     * Resolve the transcript path written for a session.
     */
    public static function transcriptPath(string $workdir, string $sessionId): string
    {
        $encoded = str_replace(['/', '.'], '-', $workdir);

        return rtrim((string) getenv('HOME'), '/')."/.claude/projects/{$encoded}/{$sessionId}.jsonl";
    }

    private function parse(string $output): ClaudeResult
    {
        $decoded = json_decode(trim($output), true);

        if (! is_array($decoded)) {
            return new ClaudeResult(
                isError: true,
                result: trim($output),
                rawJson: $output,
            );
        }

        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];

        return new ClaudeResult(
            isError: (bool) ($decoded['is_error'] ?? false),
            result: (string) ($decoded['result'] ?? ''),
            sessionId: isset($decoded['session_id']) ? (string) $decoded['session_id'] : null,
            costUsd: isset($decoded['total_cost_usd']) ? (float) $decoded['total_cost_usd'] : null,
            inputTokens: isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : null,
            outputTokens: isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null,
            numTurns: isset($decoded['num_turns']) ? (int) $decoded['num_turns'] : null,
            durationMs: isset($decoded['duration_ms']) ? (int) $decoded['duration_ms'] : null,
            rawJson: $output,
        );
    }
}
