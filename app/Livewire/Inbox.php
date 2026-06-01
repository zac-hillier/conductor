<?php

namespace App\Livewire;

use App\Enums\TaskStatus;
use App\Models\Profile;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Inbox extends Component
{
    public Profile $profile;

    /**
     * Statuses that keep a task visible until a human acts on it.
     *
     * @var array<int, TaskStatus>
     */
    public const ATTENTION_STATUSES = [
        TaskStatus::Review,
        TaskStatus::NeedsInput,
        TaskStatus::Blocked,
    ];

    public function mount(Profile $profile): void
    {
        $this->profile = $profile;
    }

    public function render()
    {
        $tasks = $this->profile->tasks()
            ->whereIn('status', array_map(fn (TaskStatus $s) => $s->value, self::ATTENTION_STATUSES))
            ->with(['events' => fn ($query) => $query->limit(1)])
            ->orderByDesc('updated_at')
            ->get();

        return view('livewire.inbox', [
            'tasks' => $tasks,
        ]);
    }
}
