<?php

namespace App\Services;

class ContextFileReader
{
    /**
     * Default cap on characters read from a single context file, so a large
     * doc can't blow the prompt budget.
     */
    public const DEFAULT_LIMIT = 4000;

    /**
     * Read a file, trimmed and truncated to a character cap. Returns null when
     * the file is missing or unreadable. Generalised from the original
     * ScopePromptBuilder::repoContext helper.
     */
    public function read(string $path, int $limit = self::DEFAULT_LIMIT): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $body = (string) file_get_contents($path);

        if (mb_strlen($body) > $limit) {
            $body = mb_substr($body, 0, $limit).'…';
        }

        return trim($body);
    }
}
