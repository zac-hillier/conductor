<?php

use App\Enums\TaskStatus;
use App\Jobs\RunTaskJob;
use App\Jobs\ScopeTaskJob;
use App\Livewire\Board;
use App\Livewire\Settings;
use App\Models\Profile;
use App\Models\Task;
use App\Services\Claude\ClaudeRunner;
use App\Services\DispatchScheduler;
use App\Services\ScopePromptBuilder;
use App\Services\TaskPromptBuilder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FakeClaudeRunner;

it('treats empty, missing and non-writable paths as invalid', function () {
    expect(Profile::factory()->make(['workdir' => null])->hasValidWorkdir())->toBeFalse()
        ->and(Profile::factory()->make(['workdir' => ''])->hasValidWorkdir())->toBeFalse()
        ->and(Profile::factory()->make(['workdir' => '/no/such/directory/here'])->hasValidWorkdir())->toBeFalse()
        ->and(Profile::factory()->make(['workdir' => sys_get_temp_dir()])->hasValidWorkdir())->toBeTrue();
});

it('blocks a task instead of running a worker in the wrong directory', function () {
    Storage::fake('local');

    $fake = new FakeClaudeRunner;
    $fake->result = FakeClaudeRunner::success();
    app()->instance(ClaudeRunner::class, $fake);

    $profile = Profile::factory()->create(['workdir' => null]);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready]);

    (new RunTaskJob($task))->handle($fake, app(TaskPromptBuilder::class));

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Blocked)
        ->and($task->runs()->count())->toBe(0)
        ->and($fake->lastPrompt)->toBeNull()
        ->and($task->events()->where('kind', 'workdir_invalid')->exists())->toBeTrue();
});

it('bounces scoping back to needs-input when the project home is invalid', function () {
    $fake = new FakeClaudeRunner;
    app()->instance(ClaudeRunner::class, $fake);

    $profile = Profile::factory()->create(['workdir' => null]);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Scoping]);

    (new ScopeTaskJob($task))->handle($fake, app(ScopePromptBuilder::class));

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::NeedsInput)
        ->and($task->runs()->count())->toBe(0)
        ->and($fake->lastPrompt)->toBeNull()
        ->and($task->comments()->count())->toBe(1)
        ->and($task->events()->where('kind', 'workdir_invalid')->exists())->toBeTrue();
});

it('does not auto-dispatch a profile without a valid project home', function () {
    Bus::fake();
    config()->set('conductor.readiness.enabled', false);

    $profile = Profile::factory()->personal()->create(['workdir' => null, 'concurrency_cap' => 3]);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready, 'priority' => 90]);

    app(DispatchScheduler::class)->tick();

    expect($task->fresh()->status)->toBe(TaskStatus::Ready);
    Bus::assertNothingDispatched();
});

it('refuses manual dispatch and explains why when the project home is invalid', function () {
    Bus::fake();

    $profile = Profile::factory()->create(['workdir' => null]);
    $task = Task::factory()->for($profile)->create(['status' => TaskStatus::Ready]);

    Livewire::test(Board::class, ['profile' => $profile])
        ->call('selectTask', $task->id)
        ->call('dispatchTask')
        ->assertSet('showDetail', true)
        ->assertSet('actionNotice', fn ($v) => is_string($v) && str_contains($v, 'project home'));

    expect($task->fresh()->status)->toBe(TaskStatus::Ready);
    Bus::assertNothingDispatched();
});

it('validates the project home when saving settings', function () {
    $profile = Profile::factory()->create(['workdir' => sys_get_temp_dir()]);

    Livewire::test(Settings::class, ['profile' => $profile])
        ->set('workdir', '/no/such/directory/here')
        ->call('save')
        ->assertHasErrors(['workdir']);

    Livewire::test(Settings::class, ['profile' => $profile])
        ->set('workdir', sys_get_temp_dir())
        ->call('save')
        ->assertHasNoErrors();

    expect($profile->fresh()->workdir)->toBe(sys_get_temp_dir());
});
