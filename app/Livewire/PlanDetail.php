<?php

namespace App\Livewire;

use App\Enums\PhaseStatus;
use App\Enums\PlanStatus;
use App\Jobs\GeneratePlanJob;
use App\Models\Plan;
use App\Models\Profile;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class PlanDetail extends Component
{
    public Profile $profile;

    public Plan $plan;

    // Add-phase form.
    public string $phaseName = '';

    public string $phaseObjective = '';

    public function mount(Profile $profile, Plan $plan): void
    {
        abort_unless($plan->project->profile_id === $profile->id, 404);

        $this->profile = $profile;
        $this->plan = $plan;
    }

    /**
     * Kick off the planning pipeline (ag-phase) to generate this plan's phases.
     */
    public function generate(): void
    {
        if ($this->plan->status !== PlanStatus::Drafting) {
            return;
        }

        GeneratePlanJob::dispatch($this->plan);
    }

    public function addPhase(): void
    {
        $validated = $this->validate([
            'phaseName' => ['required', 'string', 'max:255'],
            'phaseObjective' => ['nullable', 'string'],
        ]);

        $next = (int) $this->plan->phases()->max('number') + 1;

        $this->plan->phases()->create([
            'number' => $next,
            'name' => $validated['phaseName'],
            'objective' => $validated['phaseObjective'] ?: null,
        ]);

        $this->reset('phaseName', 'phaseObjective');
    }

    /**
     * Turn a phase into a board task (only when its predecessors are done).
     */
    public function makeExecutable(int $phaseId): void
    {
        $phase = $this->plan->phases()->findOrFail($phaseId);

        if (! $phase->isExecutable()) {
            return;
        }

        $phase->createBackingTask();
    }

    /**
     * Retry a blocked phase: reset it and spin up a fresh backing task.
     */
    public function retryPhase(int $phaseId): void
    {
        $phase = $this->plan->phases()->findOrFail($phaseId);

        if ($phase->status !== PhaseStatus::Blocked) {
            return;
        }

        $phase->update([
            'status' => PhaseStatus::Pending,
            'task_id' => null,
            'review_verdict' => null,
            'outcome' => null,
        ]);

        if ($phase->isExecutable()) {
            $phase->createBackingTask();
            $this->plan->update(['status' => PlanStatus::Executing]);
        }
    }

    public function deletePlan()
    {
        $this->plan->delete();

        return $this->redirect(route('profiles.plans', $this->profile), navigate: true);
    }

    public function render()
    {
        $this->plan->load(['phases.task', 'project']);

        $phases = $this->plan->phases;

        return view('livewire.plan-detail', [
            'plan' => $this->plan,
            'phases' => $phases,
            'doneCount' => $phases->where('status', PhaseStatus::Done)->count(),
            'pulse' => $this->plan->project->pulse(),
        ]);
    }
}
