<?php

namespace App\Livewire;

use App\Models\Plan;
use App\Models\Profile;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Plans extends Component
{
    public Profile $profile;

    public bool $showCreate = false;

    public string $name = '';

    public string $concept = '';

    public ?int $projectId = null;

    public function mount(Profile $profile): void
    {
        $this->profile = $profile;
        $this->projectId = $profile->defaultProject()?->id;
    }

    public function createPlan(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'concept' => ['nullable', 'string'],
            'projectId' => ['required', 'integer'],
        ]);

        $project = $this->profile->projects()->findOrFail($validated['projectId']);

        $project->plans()->create([
            'name' => $validated['name'],
            'slug' => Plan::uniqueSlugFor($project, $validated['name']),
            'concept' => $validated['concept'] ?: null,
        ]);

        $this->reset('name', 'concept', 'showCreate');
        $this->projectId = $this->profile->defaultProject()?->id;
    }

    public function render()
    {
        $plans = Plan::query()
            ->whereIn('project_id', $this->profile->projects()->select('id'))
            ->with(['project', 'phases'])
            ->latest()
            ->get();

        return view('livewire.plans', [
            'plans' => $plans,
            'projects' => $this->profile->projects()->orderByDesc('is_default')->orderBy('name')->get(),
        ]);
    }
}
