<?php

namespace App\Livewire;

use App\Enums\ProfileKind;
use App\Enums\TaskStatus;
use App\Models\Profile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Overview extends Component
{
    public bool $showCreate = false;

    public string $name = '';

    public string $kind = ProfileKind::Customer->value;

    public function createProfile(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(ProfileKind::class)],
        ]);

        $profile = Profile::create([
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($validated['name']),
            'kind' => $validated['kind'],
        ]);

        // Every profile has an implicit default project (workdir inherits the
        // profile home until the profile gains additional projects).
        $profile->projects()->create([
            'slug' => 'default',
            'name' => $profile->name,
            'is_default' => true,
        ]);

        $this->reset('name', 'showCreate');
        $this->kind = ProfileKind::Customer->value;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'profile';
        $slug = $base;
        $suffix = 2;

        while (Profile::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    public function render()
    {
        $profiles = Profile::query()
            ->with('tasks:id,profile_id,status')
            ->orderBy('name')
            ->get();

        return view('livewire.overview', [
            'profiles' => $profiles,
            'kinds' => ProfileKind::cases(),
            'statuses' => TaskStatus::ordered(),
        ]);
    }
}
