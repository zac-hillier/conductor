<?php

namespace App\Livewire;

use App\Models\Profile;
use App\Models\TaskEvent;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Activity extends Component
{
    public Profile $profile;

    public string $search = '';

    public string $kind = '';

    public function mount(Profile $profile): void
    {
        $this->profile = $profile;
    }

    public function render()
    {
        $events = TaskEvent::query()
            ->with('task:id,ref,title')
            ->whereHas('task', fn ($query) => $query->where('profile_id', $this->profile->id))
            ->when($this->kind !== '', fn ($query) => $query->where('kind', $this->kind))
            ->when($this->search !== '', function ($query) {
                $term = '%'.$this->search.'%';

                $query->where(function ($inner) use ($term) {
                    $inner->where('kind', 'ilike', $term)
                        ->orWhereHas('task', function ($task) use ($term) {
                            $task->where('ref', 'ilike', $term)
                                ->orWhere('title', 'ilike', $term);
                        });
                });
            })
            ->latest('created_at')
            ->latest('id')
            ->limit(200)
            ->get();

        $kinds = TaskEvent::query()
            ->whereHas('task', fn ($query) => $query->where('profile_id', $this->profile->id))
            ->distinct()
            ->orderBy('kind')
            ->pluck('kind');

        return view('livewire.activity', [
            'events' => $events,
            'kinds' => $kinds,
        ]);
    }
}
