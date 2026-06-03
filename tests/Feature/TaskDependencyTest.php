<?php

use App\Enums\TaskStatus;
use App\Jobs\RunTaskJob;
use App\Livewire\Board;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Task;
use App\Services\Claude\ClaudeRunner;
use App\Services\DispatchScheduler;
use App\Services\TaskPromptBuilder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FakeClaudeRunner;

it('reports blocked while any prerequisite is incomplete', function () {
    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $task = Task::factory()->for($profile)->create();
    $a = Task::factory()->for($profile)->create(['status' => TaskStatus::Backlog]);
    $b = Task::factory()->for($profile)->create(['status' => TaskStatus::Complete]);
    $task->dependencies()->attach([$a->id, $b->id]);

    expect($task->isBlockedByDependencies())->toBeTrue()
        ->and($task->unmetDependencies()->pluck('id')->all())->toBe([$a->id]);

    $a->update(['status' => TaskStatus::Complete]);
    expect($task->fresh()->isBlockedByDependencies())->toBeFalse();
});

it('refuses to scope or dispatch a task with unmet prerequisites', function () {
    Bus::fake();

    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $prereq = Task::factory()->for($profile)->create(['status' => TaskStatus::Backlog]);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Backlog]);
    $task->dependencies()->attach($prereq->id);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->call('scopeTask')
        ->assertSet('actionNotice', fn ($v) => is_string($v) && str_contains($v, 'prerequisite'));

    expect($task->fresh()->status)->toBe(TaskStatus::Backlog);
    Bus::assertNothingDispatched();

    $ready = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready]);
    $ready->dependencies()->attach($prereq->id);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $ready->id)
        ->call('dispatchTask')
        ->assertSet('actionNotice', fn ($v) => is_string($v) && str_contains($v, 'prerequisite'));

    expect($ready->fresh()->status)->toBe(TaskStatus::Ready);
});

it('does not auto-dispatch a dependency-blocked task', function () {
    Bus::fake();
    config()->set('conductor.readiness.enabled', false);

    $profile = Profile::factory()->personal()->create(['workdir' => sys_get_temp_dir()]);
    $prereq = Task::factory()->for($profile)->create(['status' => TaskStatus::Backlog]);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready, 'priority' => 90]);
    $task->dependencies()->attach($prereq->id);

    app(DispatchScheduler::class)->tick();

    expect($task->fresh()->status)->toBe(TaskStatus::Ready);
    Bus::assertNothingDispatched();
});

it('supports a cross-project prerequisite within the same profile', function () {
    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $alpha = Project::factory()->for($profile)->create();
    $beta = Project::factory()->for($profile)->create();
    $task = Task::factory()->for($profile)->create(['project_id' => $alpha->id]);
    $prereq = Task::factory()->for($profile)->create(['project_id' => $beta->id, 'status' => TaskStatus::Backlog]);

    $task->dependencies()->attach($prereq->id);

    expect($task->isBlockedByDependencies())->toBeTrue();
});

it('flags and records dependents unblocked when the last prerequisite completes', function () {
    Storage::fake('local');

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::success();
    app()->instance(ClaudeRunner::class, $fake);

    $profile = Profile::factory()->personal()->create(['workdir' => sys_get_temp_dir()]);
    $prereq = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready]);
    $dependent = Task::factory()->for($profile)->create(['status' => TaskStatus::Backlog]);
    $dependent->dependencies()->attach($prereq->id);

    // Running the prerequisite to completion (personal => auto-complete) fires the notice.
    (new RunTaskJob($prereq))->handle($fake, app(TaskPromptBuilder::class));

    expect($prereq->fresh()->status)->toBe(TaskStatus::Complete)
        ->and($dependent->events()->where('kind', 'dependencies_met')->exists())->toBeTrue()
        ->and($dependent->fresh()->isBlockedByDependencies())->toBeFalse();
});

it('rejects a dependency that would create a cycle', function () {
    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $a = Task::factory()->for($profile)->create();
    $b = Task::factory()->for($profile)->create();
    $a->dependencies()->attach($b->id); // a depends on b

    // Now try to make b depend on a -> cycle.
    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $b->id)
        ->set('dependencyToAdd', $a->id)
        ->call('addDependency')
        ->assertSet('actionNotice', fn ($v) => is_string($v) && str_contains($v, 'circular'));

    expect($b->dependencies()->count())->toBe(0);
});

it('adds and removes a prerequisite from the drawer', function () {
    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);
    $task = Task::factory()->for($profile)->create();
    $prereq = Task::factory()->for($profile)->create();

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->set('dependencyToAdd', $prereq->id)
        ->call('addDependency');

    expect($task->dependencies()->pluck('tasks.id')->all())->toBe([$prereq->id]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->call('removeDependency', $prereq->id);

    expect($task->dependencies()->count())->toBe(0);
});
