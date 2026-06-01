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

        $lines[] = '';
        $lines[] = 'Complete the task in this working directory. When finished, give a concise summary of what you changed.';

        return implode("\n", $lines);
    }
}
