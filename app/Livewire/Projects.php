<?php

namespace App\Livewire;

use App\Jobs\DiscoverProjectJob;
use App\Models\Profile;
use App\Models\Project;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Projects extends Component
{
    public Profile $profile;

    // Create form.
    public string $newName = '';

    public string $newWorkdir = '';

    // Inline edit state.
    public ?int $editingId = null;

    public string $editName = '';

    public string $editWorkdir = '';

    public function mount(Profile $profile): void
    {
        $this->profile = $profile;
    }

    /**
     * A workdir is optional (empty = inherit the profile home); when given it
     * must be an existing, writable directory — same bar as the dispatch gate.
     */
    private function workdirRule(): array
    {
        return ['nullable', 'string', function (string $attribute, mixed $value, callable $fail): void {
            $dir = trim((string) $value);

            if ($dir === '') {
                return;
            }

            if (! is_dir($dir)) {
                $fail('The working directory must be an existing directory.');
            } elseif (! is_writable($dir)) {
                $fail('The working directory must be writable.');
            }
        }];
    }

    public function createProject(): void
    {
        $validated = $this->validate([
            'newName' => ['required', 'string', 'max:255'],
            'newWorkdir' => $this->workdirRule(),
        ]);

        $project = $this->profile->projects()->create([
            'slug' => $this->uniqueSlug($validated['newName']),
            'name' => $validated['newName'],
            'workdir' => trim($validated['newWorkdir']) ?: null,
        ]);

        $project->discoverContext();

        $this->reset('newName', 'newWorkdir');
    }

    public function editProject(int $projectId): void
    {
        $project = $this->profile->projects()->findOrFail($projectId);

        $this->editingId = $project->id;
        $this->editName = $project->name;
        $this->editWorkdir = (string) $project->workdir;
    }

    public function saveProject(): void
    {
        if ($this->editingId === null) {
            return;
        }

        $validated = $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editWorkdir' => $this->workdirRule(),
        ]);

        $project = $this->profile->projects()->findOrFail($this->editingId);
        $project->update([
            'name' => $validated['editName'],
            'workdir' => trim($validated['editWorkdir']) ?: null,
        ]);

        // Workdir may have changed — re-scan the project's context.
        $project->discoverContext();

        $this->reset('editingId', 'editName', 'editWorkdir');
    }

    public function rescan(int $projectId): void
    {
        $this->profile->projects()->findOrFail($projectId)->discoverContext();
    }

    /**
     * Queue an agent survey to PROPOSE a role map for a project whose folders
     * don't match the naming conventions. The proposal awaits confirmation.
     */
    public function deepScan(int $projectId): void
    {
        DiscoverProjectJob::dispatch($this->profile->projects()->findOrFail($projectId));
    }

    public function confirmProposal(int $projectId): void
    {
        $project = $this->profile->projects()->findOrFail($projectId);
        $settings = $project->settings ?? [];
        $proposal = $settings['context_proposal'] ?? null;

        if ($proposal === null) {
            return;
        }

        unset($settings['context_proposal']);

        $project->update([
            'context_map' => [
                'discovered_at' => now()->toIso8601String(),
                'manifest' => $project->context_map['manifest'] ?? null,
                'roles' => $proposal['roles'],
                'entry_doc' => $proposal['entry_doc'] ?? null,
            ],
            'settings' => $settings ?: null,
        ]);
    }

    public function discardProposal(int $projectId): void
    {
        $project = $this->profile->projects()->findOrFail($projectId);
        $settings = $project->settings ?? [];
        unset($settings['context_proposal']);
        $project->update(['settings' => $settings ?: null]);
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editName', 'editWorkdir');
    }

    public function deleteProject(int $projectId): void
    {
        $project = $this->profile->projects()->findOrFail($projectId);

        // The default project is the profile's floor — never deletable.
        if ($project->is_default) {
            return;
        }

        $project->delete();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'project';
        $slug = $base;
        $suffix = 2;

        while ($this->profile->projects()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    public function render()
    {
        return view('livewire.projects', [
            'projects' => $this->profile->projects()
                ->withCount('tasks')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
