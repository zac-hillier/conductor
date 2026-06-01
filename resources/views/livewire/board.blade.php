<div>
    <div class="flex items-center gap-3">
        <flux:button href="{{ route('overview') }}" variant="ghost" size="sm" icon="arrow-left" wire:navigate>
            Overview
        </flux:button>
    </div>

    <div class="mt-4">
        <flux:heading size="xl">{{ $profile->name }}</flux:heading>
        <flux:subheading>Board</flux:subheading>
    </div>

    <div class="mt-8 flex gap-4 overflow-x-auto pb-4">
        @foreach ($columns as $column)
            <div class="flex w-72 shrink-0 flex-col" data-status="{{ $column->value }}">
                <div class="flex items-center gap-2 pt-2">
                    <flux:badge size="sm" color="{{ $column->color() }}">{{ $column->label() }}</flux:badge>
                </div>
                <div class="mt-3 min-h-32 space-y-2 rounded-lg bg-zinc-100/70 p-2 dark:bg-zinc-800/50">
                    {{-- Cards arrive in a later phase. --}}
                </div>
            </div>
        @endforeach
    </div>
</div>
