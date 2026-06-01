<?php

namespace Tests\Support;

use App\Services\Claude\ClaudeResult;
use App\Services\Claude\ClaudeRunner;

class FakeClaudeRunner implements ClaudeRunner
{
    public ?ClaudeResult $result = null;

    public ?string $lastPrompt = null;

    public ?string $lastWorkdir = null;

    /**
     * @var array<string, mixed>
     */
    public array $lastOptions = [];

    public function run(string $prompt, string $workdir, array $options = []): ClaudeResult
    {
        $this->lastPrompt = $prompt;
        $this->lastWorkdir = $workdir;
        $this->lastOptions = $options;

        return $this->result ?? self::success();
    }

    public static function success(): ClaudeResult
    {
        return new ClaudeResult(
            isError: false,
            result: 'Implemented the change and verified it.',
            sessionId: 'sess-success-123',
            costUsd: 0.0421,
            inputTokens: 1200,
            outputTokens: 800,
            numTurns: 4,
            durationMs: 5300,
            rawJson: json_encode([
                'is_error' => false,
                'result' => 'Implemented the change and verified it.',
                'session_id' => 'sess-success-123',
                'total_cost_usd' => 0.0421,
                'usage' => ['input_tokens' => 1200, 'output_tokens' => 800],
                'num_turns' => 4,
                'duration_ms' => 5300,
            ]),
        );
    }

    /**
     * Build a successful result whose `result` is the given JSON payload string.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function json(array $payload): ClaudeResult
    {
        $result = (string) json_encode($payload);

        return new ClaudeResult(
            isError: false,
            result: $result,
            sessionId: 'sess-scope-789',
            costUsd: 0.0185,
            inputTokens: 900,
            outputTokens: 300,
            numTurns: 2,
            durationMs: 2100,
            rawJson: (string) json_encode([
                'is_error' => false,
                'result' => $result,
                'session_id' => 'sess-scope-789',
                'total_cost_usd' => 0.0185,
                'usage' => ['input_tokens' => 900, 'output_tokens' => 300],
                'num_turns' => 2,
                'duration_ms' => 2100,
            ]),
        );
    }

    /**
     * Build a successful result whose `result` is arbitrary (non-JSON) text.
     */
    public static function text(string $text): ClaudeResult
    {
        return new ClaudeResult(
            isError: false,
            result: $text,
            sessionId: 'sess-scope-text',
            costUsd: 0.0099,
            inputTokens: 500,
            outputTokens: 120,
            rawJson: (string) json_encode([
                'is_error' => false,
                'result' => $text,
                'session_id' => 'sess-scope-text',
                'total_cost_usd' => 0.0099,
                'usage' => ['input_tokens' => 500, 'output_tokens' => 120],
            ]),
        );
    }

    public static function error(): ClaudeResult
    {
        return new ClaudeResult(
            isError: true,
            result: 'Could not complete: permission denied.',
            sessionId: 'sess-error-456',
            costUsd: 0.0102,
            inputTokens: 400,
            outputTokens: 100,
            numTurns: 1,
            durationMs: 900,
            rawJson: json_encode([
                'is_error' => true,
                'result' => 'Could not complete: permission denied.',
                'session_id' => 'sess-error-456',
                'total_cost_usd' => 0.0102,
                'usage' => ['input_tokens' => 400, 'output_tokens' => 100],
            ]),
        );
    }
}
