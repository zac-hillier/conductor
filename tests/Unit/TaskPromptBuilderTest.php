<?php

use App\Models\Task;
use App\Services\TaskPromptBuilder;

it('includes definition of done, constraints and target paths', function () {
    $task = new Task([
        'title' => 'Add caching layer',
        'summary' => 'Speed up the dashboard.',
        'definition_of_done' => ['Cache the report query', 'Add a feature test'],
        'constraints' => ['Do not change the public API'],
        'target_paths' => ['app/Services/Report.php'],
    ]);

    $prompt = (new TaskPromptBuilder)->build($task);

    expect($prompt)
        ->toContain('Add caching layer')
        ->toContain('Speed up the dashboard.')
        ->toContain('Cache the report query')
        ->toContain('Add a feature test')
        ->toContain('Do not change the public API')
        ->toContain('app/Services/Report.php');
});

it('omits empty sections', function () {
    $task = new Task(['title' => 'Bare task']);

    $prompt = (new TaskPromptBuilder)->build($task);

    expect($prompt)
        ->toContain('Bare task')
        ->not->toContain('Constraints you must respect')
        ->not->toContain('Relevant target paths');
});
