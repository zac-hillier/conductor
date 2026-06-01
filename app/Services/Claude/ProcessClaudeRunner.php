<?php

namespace App\Services\Claude;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

class ProcessClaudeRunner implements ClaudeRunner
{
    public function run(string $prompt, string $workdir, array $options = []): ClaudeResult
    {
        $command = $this->buildCommand($prompt, $options);

        $process = new Process($command, $workdir);
        $process->setTimeout((float) config('conductor.claude.timeout'));

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

        return array_merge([
            $binary,
            '-p', $prompt,
            '--output-format', 'json',
            '--permission-mode', $permissionMode,
            '--model', $model,
        ], array_values($extra));
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
