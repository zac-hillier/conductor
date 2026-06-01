<?php

namespace App\Services\Claude;

interface ClaudeRunner
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function run(string $prompt, string $workdir, array $options = []): ClaudeResult;
}
