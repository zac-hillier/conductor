<?php

namespace App\Livewire;

use App\Models\Profile;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Settings extends Component
{
    public Profile $profile;

    public string $workdir = '';

    public string $permissionMode = 'default';

    public bool $requireReview = false;

    public bool $autoDispatch = false;

    public int $concurrencyCap = 3;

    public string $disallowedTools = '';

    public bool $saved = false;

    /**
     * @var array<int, string>
     */
    public const PERMISSION_MODES = ['bypassPermissions', 'acceptEdits', 'default', 'plan'];

    public function mount(Profile $profile): void
    {
        $this->profile = $profile;
        $this->workdir = (string) $profile->workdir;

        $policy = $profile->policyOrDefault();
        $this->permissionMode = $policy->permissionMode();
        $this->requireReview = $policy->requiresReview();
        $this->autoDispatch = $policy->autoDispatch();
        $this->disallowedTools = implode("\n", $policy->disallowedTools());
        $this->concurrencyCap = (int) $profile->concurrency_cap;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'workdir' => ['required', 'string', function (string $attribute, mixed $value, callable $fail): void {
                $dir = trim((string) $value);

                if ($dir === '' || ! is_dir($dir)) {
                    $fail('The project home must be an existing directory.');
                } elseif (! is_writable($dir)) {
                    $fail('The project home must be writable.');
                }
            }],
            'permissionMode' => ['required', 'in:'.implode(',', self::PERMISSION_MODES)],
            'requireReview' => ['boolean'],
            'autoDispatch' => ['boolean'],
            'concurrencyCap' => ['required', 'integer', 'min:1'],
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
            'auto_dispatch' => (bool) $validated['autoDispatch'],
        ];

        $this->profile->policy()->updateOrCreate(
            ['profile_id' => $this->profile->id],
            ['rules' => $rules],
        );

        $this->profile->update([
            'workdir' => trim($validated['workdir']),
            'concurrency_cap' => $validated['concurrencyCap'],
        ]);

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
