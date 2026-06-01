<?php

namespace App\Livewire;

use App\Models\Profile;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Settings extends Component
{
    public Profile $profile;

    public string $permissionMode = 'default';

    public bool $requireReview = false;

    public string $disallowedTools = '';

    public bool $saved = false;

    /**
     * @var array<int, string>
     */
    public const PERMISSION_MODES = ['bypassPermissions', 'acceptEdits', 'default', 'plan'];

    public function mount(Profile $profile): void
    {
        $this->profile = $profile;

        $policy = $profile->policyOrDefault();
        $this->permissionMode = $policy->permissionMode();
        $this->requireReview = $policy->requiresReview();
        $this->disallowedTools = implode("\n", $policy->disallowedTools());
    }

    public function save(): void
    {
        $validated = $this->validate([
            'permissionMode' => ['required', 'in:'.implode(',', self::PERMISSION_MODES)],
            'requireReview' => ['boolean'],
            'disallowedTools' => ['nullable', 'string'],
        ]);

        $tools = collect(preg_split('/\r\n|\r|\n/', $validated['disallowedTools'] ?? ''))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->values()
            ->all();

        $rules = [
            'permission_mode' => $validated['permissionMode'],
            'disallowed_tools' => $tools,
            'require_review' => (bool) $validated['requireReview'],
        ];

        $this->profile->policy()->updateOrCreate(
            ['profile_id' => $this->profile->id],
            ['rules' => $rules],
        );

        $this->profile->refresh();
        $this->saved = true;
    }

    public function render()
    {
        return view('livewire.settings', [
            'modes' => self::PERMISSION_MODES,
        ]);
    }
}
