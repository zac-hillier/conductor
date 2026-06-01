<?php

namespace App\Livewire;

use App\Enums\TaskStatus;
use App\Models\Profile;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Board extends Component
{
    public Profile $profile;

    public function mount(Profile $profile): void
    {
        $this->profile = $profile;
    }

    public function render()
    {
        return view('livewire.board', [
            'columns' => TaskStatus::ordered(),
        ]);
    }
}
