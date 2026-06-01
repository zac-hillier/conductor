<div>
    <div class="flex items-center justify-between gap-3">
        <flux:button href="{{ route('profiles.board', $profile) }}" variant="ghost" size="sm" icon="arrow-left" wire:navigate>
            Board
        </flux:button>
    </div>

    <div class="mt-4">
        <flux:heading size="xl">{{ $profile->name }}</flux:heading>
        <flux:subheading>Policy settings</flux:subheading>
    </div>

    <form wire:submit="save" class="mt-8 max-w-lg space-y-6">
        @if ($saved)
            <flux:callout variant="success" icon="check-circle">
                <flux:callout.text>Policy saved.</flux:callout.text>
            </flux:callout>
        @endif

        <flux:select wire:model="permissionMode" label="Permission mode">
            @foreach ($modes as $mode)
                <flux:select.option value="{{ $mode }}">{{ $mode }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:switch wire:model="requireReview" label="Require human review before complete" />

        <flux:textarea
            wire:model="disallowedTools"
            label="Disallowed tools"
            rows="5"
            placeholder="Bash(git push:*)"
        />
        <flux:text size="sm" class="text-zinc-400">One pattern per line. Passed to the worker as --disallowedTools.</flux:text>

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary">Save policy</flux:button>
        </div>
    </form>
</div>
