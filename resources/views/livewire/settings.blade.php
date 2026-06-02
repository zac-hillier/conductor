<div class="mx-auto max-w-7xl">
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

        <flux:input
            wire:model="workdir"
            label="Project home"
            placeholder="/home/zac/projects/my-project"
        />
        <flux:text size="sm" class="text-zinc-400">Absolute path to this profile's working directory. Workers run here — a task cannot be dispatched until this is a valid, writable directory.</flux:text>

        <flux:select wire:model="permissionMode" label="Permission mode">
            @foreach ($modes as $mode)
                <flux:select.option value="{{ $mode }}">{{ $mode }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:switch wire:model="requireReview" label="Require human review before complete" />

        <flux:switch wire:model="autoDispatch" label="Auto-dispatch ready tasks" />
        <flux:text size="sm" class="text-zinc-400">When on, the scheduler claims and runs ready tasks for this profile up to the concurrency cap.</flux:text>

        <flux:input
            type="number"
            wire:model="concurrencyCap"
            label="Concurrency cap"
            min="1"
        />
        <flux:text size="sm" class="text-zinc-400">Maximum number of tasks running at once for this profile.</flux:text>

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
