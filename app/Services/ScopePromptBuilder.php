<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskComment;

class ScopePromptBuilder
{
    /**
     * Maximum characters read from each context file in the workdir.
     */
    private const CONTEXT_FILE_LIMIT = 4000;

    public function build(Task $task): string
    {
        $lines = [];

        $lines[] = 'You are a scoping assistant. Your job is to decide whether a task is';
        $lines[] = 'specified clearly enough to hand to an autonomous worker, and to refine it.';
        $lines[] = 'You have read-only access (Read, Grep, Glob) to the working directory so you';
        $lines[] = 'can inspect the repository for context. Do not attempt to modify anything.';

        $profile = $task->profile;
        if ($profile !== null) {
            $lines[] = '';
            $lines[] = 'Profile context:';
            $lines[] = '- Name: '.$profile->name;
            $lines[] = '- Kind: '.$profile->kind->label();

            if (! empty($profile->workdir)) {
                $lines[] = '- Working directory: '.$profile->workdir;
            }

            if (! empty($profile->repo_url)) {
                $lines[] = '- Repository: '.$profile->repo_url;
            }

            $context = $this->repoContext($profile->workdir);
            if ($context !== []) {
                $lines[] = '';
                $lines[] = 'Project context files (read from the working directory):';
                foreach ($context as $name => $body) {
                    $lines[] = '';
                    $lines[] = '--- '.$name.' ---';
                    $lines[] = $body;
                }
            } else {
                $lines[] = 'Read README.md, CONDUCTOR.md and CLAUDE.md in the working directory (if present) for project context.';
            }
        }

        $lines[] = '';
        $lines[] = 'Task brief:';
        $lines[] = '- Title: '.$task->title;

        if (! empty($task->summary)) {
            $lines[] = '- Summary: '.$task->summary;
        }

        $dod = $task->definition_of_done ?? [];
        if ($dod !== []) {
            $lines[] = '- Existing definition of done:';
            foreach ($dod as $item) {
                $lines[] = '  - '.$item;
            }
        }

        $constraints = $task->constraints ?? [];
        if ($constraints !== []) {
            $lines[] = '- Existing constraints:';
            foreach ($constraints as $item) {
                $lines[] = '  - '.$item;
            }
        }

        $targets = $task->target_paths ?? [];
        if ($targets !== []) {
            $lines[] = '- Existing target paths:';
            foreach ($targets as $item) {
                $lines[] = '  - '.$item;
            }
        }

        $thread = $this->thread($task);
        if ($thread !== []) {
            $lines[] = '';
            $lines[] = 'Conversation so far (oldest first):';
            foreach ($thread as $entry) {
                $lines[] = $entry;
            }
        }

        $lines[] = '';
        $lines[] = 'Decide whether the task is clearly enough specified to hand to a worker.';
        $lines[] = 'If the brief is objectless, ambiguous, or missing essential detail, push back:';
        $lines[] = 'set "ready" to false and ask specific, answerable questions.';
        $lines[] = 'If it is clear, set "ready" to true and return a refined title and summary plus';
        $lines[] = 'a concrete, filesystem-verifiable definition of done.';
        $lines[] = '';
        $lines[] = 'Respond with ONLY a single JSON object, no prose around it, of this exact shape:';
        $lines[] = '{"ready": bool, "questions": string[], "title": string|null, "summary": string|null,';
        $lines[] = ' "definition_of_done": string[], "constraints": string[], "target_paths": string[], "rationale": string}';

        return implode("\n", $lines);
    }

    /**
     * @return array<string, string>
     */
    private function repoContext(?string $workdir): array
    {
        if (empty($workdir) || ! is_dir($workdir)) {
            return [];
        }

        $context = [];

        foreach (['README.md', 'CONDUCTOR.md', 'CLAUDE.md'] as $name) {
            $path = rtrim($workdir, '/').'/'.$name;

            if (! is_file($path) || ! is_readable($path)) {
                continue;
            }

            $body = (string) file_get_contents($path);

            if (mb_strlen($body) > self::CONTEXT_FILE_LIMIT) {
                $body = mb_substr($body, 0, self::CONTEXT_FILE_LIMIT).'…';
            }

            $context[$name] = trim($body);
        }

        return $context;
    }

    /**
     * @return array<int, string>
     */
    private function thread(Task $task): array
    {
        return $task->comments()
            ->reorder('id')
            ->get()
            ->map(function (TaskComment $comment): string {
                $who = $comment->isAgent() ? 'Agent' : 'Human';

                return $who.': '.$comment->body;
            })
            ->all();
    }
}
