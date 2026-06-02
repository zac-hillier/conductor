<?php

namespace App\Services;

use App\Models\Task;

class TaskPromptBuilder
{
    public function build(Task $task): string
    {
        $lines = [];

        $lines[] = 'You are executing a development task in the current working directory.';

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

            $lines[] = 'Before acting, read README.md, CONDUCTOR.md and CLAUDE.md in the working directory (if present) for project context.';
        }

        $lines[] = '';
        $lines[] = 'Task: '.$task->title;

        if (! empty($task->summary)) {
            $lines[] = '';
            $lines[] = 'Summary:';
            $lines[] = $task->summary;
        }

        $dod = $task->definition_of_done ?? [];
        if ($dod !== []) {
            $lines[] = '';
            $lines[] = 'Your definition of done is to complete every one of these:';
            foreach ($dod as $item) {
                $lines[] = '- '.$item;
            }
        }

        $constraints = $task->constraints ?? [];
        if ($constraints !== []) {
            $lines[] = '';
            $lines[] = 'Constraints you must respect:';
            foreach ($constraints as $item) {
                $lines[] = '- '.$item;
            }
        }

        $targets = $task->target_paths ?? [];
        if ($targets !== []) {
            $lines[] = '';
            $lines[] = 'Relevant target paths:';
            foreach ($targets as $item) {
                $lines[] = '- '.$item;
            }
        }

        $disallowed = $profile?->policyOrDefault()->disallowedTools() ?? [];
        if ($disallowed !== []) {
            $lines[] = '';
            $lines[] = 'You are NOT permitted to run these tools:';
            foreach ($disallowed as $tool) {
                $lines[] = '- '.$tool;
            }
            $lines[] = 'If completing the task REQUIRES one of these, do NOT attempt it. Instead, finish what you can and request approval. To request approval, include at the very end of your output a block in exactly this format (one line per request):';
            $lines[] = '';
            $lines[] = 'APPROVALS_REQUESTED:';
            $lines[] = '- <tool-pattern> :: <command you would run> :: <why you need it>';
            $lines[] = '';
            $lines[] = 'The <tool-pattern> must be one of the disallowed patterns listed above. A human will grant or deny, and the task may be re-run with the capability permitted.';
        }

        $lines[] = '';
        $lines[] = 'Complete the task in this working directory. When finished, give a concise summary of what you changed.';

        return implode("\n", $lines);
    }
}
