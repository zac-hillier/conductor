<?php

use App\Enums\TaskStatus;
use App\Models\Profile;
use App\Models\Task;
use App\Services\TasksYamlImporter;

function writeTasksYaml(): string
{
    $yaml = <<<'YAML'
client: Widget Shop
repo: /home/zac/widgetshop
next_id: 23
open:
  - id: ws-020
    summary: |
      Add an export button to the orders page
      It should produce a CSV download.
    definition_of_done:
      - Button visible on orders page
      - CSV downloads with all columns
    constraints:
      - Do not change the orders schema
    target_paths:
      - app/Livewire/Orders.php
    created: 2026-05-01
in_progress:
  - id: ws-021
    summary: Refactor the basket totals calculation
    definition_of_done:
      - Totals match the legacy figures
    created: 2026-05-02
blocked:
  - id: ws-022
    summary: Integrate the payment gateway
    definition_of_done:
      - Successful test charge
    questions:
      - Which gateway credentials should we use?
      - Is sandbox mode acceptable for now?
    created: 2026-05-03
done:
  - id: ws-019
    summary: Fix the login redirect loop
    created: 2026-04-28
YAML;

    $path = sys_get_temp_dir().'/conductor-tasks-'.uniqid().'.yaml';
    file_put_contents($path, $yaml);

    return $path;
}

it('creates the profile and tasks with mapped statuses', function () {
    $path = writeTasksYaml();

    $summary = app(TasksYamlImporter::class)->import('widgetshop', $path);

    expect($summary['created'])->toBe(4)
        ->and($summary['updated'])->toBe(0);

    $profile = Profile::query()->where('slug', 'widgetshop')->firstOrFail();
    expect($profile->name)->toBe('Widget Shop')
        ->and($profile->workdir)->toBe('/home/zac/widgetshop')
        ->and($profile->policy)->not->toBeNull();

    $statusFor = fn (string $ref) => Task::query()->where('ref', $ref)->firstOrFail()->status;

    expect($statusFor('ws-020'))->toBe(TaskStatus::Ready)
        ->and($statusFor('ws-021'))->toBe(TaskStatus::Ready)
        ->and($statusFor('ws-022'))->toBe(TaskStatus::Blocked)
        ->and($statusFor('ws-019'))->toBe(TaskStatus::Complete);

    $exportTask = Task::query()->where('ref', 'ws-020')->firstOrFail();
    expect($exportTask->title)->toBe('Add an export button to the orders page')
        ->and($exportTask->definition_of_done)->toContain('Button visible on orders page')
        ->and($exportTask->constraints)->toContain('Do not change the orders schema')
        ->and($exportTask->target_paths)->toContain('app/Livewire/Orders.php');

    @unlink($path);
});

it('carries blocked-task questions into an agent comment', function () {
    $path = writeTasksYaml();

    app(TasksYamlImporter::class)->import('widgetshop', $path);

    $blocked = Task::query()->where('ref', 'ws-022')->firstOrFail();
    $comment = $blocked->comments()->where('author', 'agent')->firstOrFail();

    expect($comment->body)->toContain('Which gateway credentials should we use?')
        ->toContain('Is sandbox mode acceptable for now?');

    @unlink($path);
});

it('is idempotent on re-run', function () {
    $path = writeTasksYaml();

    app(TasksYamlImporter::class)->import('widgetshop', $path);
    $second = app(TasksYamlImporter::class)->import('widgetshop', $path);

    expect($second['created'])->toBe(0)
        ->and($second['updated'])->toBe(4);

    expect(Profile::query()->count())->toBe(1)
        ->and(Task::query()->count())->toBe(4);

    $blocked = Task::query()->where('ref', 'ws-022')->firstOrFail();
    expect($blocked->comments()->where('author', 'agent')->count())->toBe(1);

    @unlink($path);
});

it('persists nothing on a dry run and leaves the source file untouched', function () {
    $path = writeTasksYaml();
    $before = file_get_contents($path);

    $summary = app(TasksYamlImporter::class)->import('widgetshop', $path, dryRun: true);

    expect($summary['created'])->toBe(4);
    expect(Profile::query()->count())->toBe(0)
        ->and(Task::query()->count())->toBe(0);

    expect(file_get_contents($path))->toBe($before);

    @unlink($path);
});

it('reports the mapping via the command dry run', function () {
    $path = writeTasksYaml();

    $this->artisan('conductor:import widgetshop --path='.$path.' --dry-run')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(Profile::query()->count())->toBe(0);

    @unlink($path);
});
