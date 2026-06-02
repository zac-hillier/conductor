<?php

use App\Enums\TaskStatus;
use App\Jobs\ScoreTaskJob;
use App\Livewire\Board;
use App\Models\Profile;
use App\Models\Task;
use App\Services\TasksYamlImporter;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

it('enterReady dispatches scoring for a ready task with no score', function () {
    Bus::fake();

    $task = Task::factory()->create([
        'status' => TaskStatus::Ready,
        'readiness_score' => null,
    ]);

    $task->enterReady();

    Bus::assertDispatched(ScoreTaskJob::class, fn (ScoreTaskJob $job) => $job->task->is($task));
});

it('enterReady does nothing when a score already exists', function () {
    Bus::fake();

    $task = Task::factory()->create([
        'status' => TaskStatus::Ready,
        'readiness_score' => 70,
    ]);

    $task->enterReady();

    Bus::assertNotDispatched(ScoreTaskJob::class);
});

it('enterReady does nothing for a non-ready task', function () {
    Bus::fake();

    $task = Task::factory()->create([
        'status' => TaskStatus::Backlog,
        'readiness_score' => null,
    ]);

    $task->enterReady();

    Bus::assertNotDispatched(ScoreTaskJob::class);
});

it('moving a task to ready via the board enqueues scoring', function () {
    Bus::fake();

    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create([
        'status' => TaskStatus::Review,
        'readiness_score' => null,
    ]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('moveTask', $task->id, TaskStatus::Ready->value);

    Bus::assertDispatched(ScoreTaskJob::class, fn (ScoreTaskJob $job) => $job->task->is($task));
});

it('updating a task to ready via the drawer enqueues scoring', function () {
    Bus::fake();

    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create([
        'status' => TaskStatus::Backlog,
        'readiness_score' => null,
    ]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->set('editStatus', TaskStatus::Ready->value)
        ->call('updateTask');

    Bus::assertDispatched(ScoreTaskJob::class, fn (ScoreTaskJob $job) => $job->task->is($task));
});

it('retrying a blocked task to ready enqueues scoring', function () {
    Bus::fake();

    $profile = Profile::factory()->create();
    $task = Task::factory()->for($profile)->create([
        'status' => TaskStatus::Blocked,
        'readiness_score' => null,
    ]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->call('retryTask');

    Bus::assertDispatched(ScoreTaskJob::class, fn (ScoreTaskJob $job) => $job->task->is($task));
});

it('the importer enqueues scoring for imported ready tasks', function () {
    Bus::fake();

    $dir = sys_get_temp_dir().'/conductor-import-'.uniqid();
    mkdir($dir, 0777, true);
    $path = $dir.'/tasks.yaml';
    file_put_contents($path, <<<'YAML'
    client: Widget Shop
    repo: /tmp/widgetshop
    open:
      - id: ws-001
        summary: Build the thing
    YAML);

    app(TasksYamlImporter::class)->import('widgetshop', $path);

    Bus::assertDispatched(ScoreTaskJob::class, fn (ScoreTaskJob $job) => $job->task->ref === 'ws-001');

    @unlink($path);
    @rmdir($dir);
});

it('conductor:score-ready enqueues scoring for null-score ready tasks and reports the count', function () {
    Bus::fake();

    $profile = Profile::factory()->create();
    $ready = Task::factory()->for($profile)->count(2)->create([
        'status' => TaskStatus::Ready,
        'readiness_score' => null,
    ]);
    // Should be ignored: already scored, and not ready.
    Task::factory()->for($profile)->create(['status' => TaskStatus::Ready, 'readiness_score' => 80]);
    Task::factory()->for($profile)->create(['status' => TaskStatus::Backlog, 'readiness_score' => null]);

    $this->artisan('conductor:score-ready')
        ->expectsOutputToContain('Enqueued readiness scoring for 2 ready task(s)')
        ->assertSuccessful();

    Bus::assertDispatchedTimes(ScoreTaskJob::class, 2);
});

it('conductor:score-ready can be scoped to a single profile', function () {
    Bus::fake();

    $a = Profile::factory()->create(['slug' => 'alpha']);
    $b = Profile::factory()->create(['slug' => 'beta']);

    Task::factory()->for($a)->create(['status' => TaskStatus::Ready, 'readiness_score' => null]);
    Task::factory()->for($b)->create(['status' => TaskStatus::Ready, 'readiness_score' => null]);

    $this->artisan('conductor:score-ready', ['--profile' => 'alpha'])
        ->assertSuccessful();

    Bus::assertDispatchedTimes(ScoreTaskJob::class, 1);
});

it('renders the unscored state on a card for a null-score ready task', function () {
    Bus::fake();

    $profile = Profile::factory()->create();
    Task::factory()->for($profile)->create([
        'status' => TaskStatus::Ready,
        'readiness_score' => null,
        'title' => 'Awaiting score',
    ]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->assertSee('Unscored')
        ->assertSeeHtml('data-readiness="unscored"');
});
