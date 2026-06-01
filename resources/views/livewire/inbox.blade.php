<div>
    <div class="flex items-center justify-between gap-3">
        <flux:button href="{{ route('overview') }}" variant="ghost" size="sm" icon="arrow-left" wire:navigate>
            Overview
        </flux:button>

        <div class="flex items-center gap-2">
            <flux:button href="{{ route('profiles.board', $profile) }}" variant="ghost" size="sm" icon="view-columns" wire:navigate>
                Board
            </flux:button>
            <flux:button href="{{ route('profiles.activity', $profile) }}" variant="ghost" size="sm" icon="clock" wire:navigate>
                Activity
            </flux:button>
        </div>
    </div>

    <div class="mt-4">
        <flux:heading size="xl">{{ $profile->name }}</flux:heading>
        <flux:subheading>Inbox — needs attention</flux:subheading>
    </div>

    @if ($tasks->isEmpty())
        <flux:callout class="mt-10" icon="check-circle">
            <flux:callout.heading>Nothing needs attention</flux:callout.heading>
            <flux:callout.text>No tasks in review, needs input, or blocked.</flux:callout.text>
        </flux:callout>
    @else
        <div class="mt-8 space-y-2">
            @foreach ($tasks as $task)
                @php($lastEvent = $task->events->first())
                <a
                    href="{{ route('profiles.board', $profile) }}"
                    wire:navigate
                    wire:key="inbox-{{ $task->id }}"
                    class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm transition hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900"
                >
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="font-mono text-xs text-zinc-400">{{ $task->ref }}</span>
                            <flux:badge size="sm" color="{{ $task->status->color() }}">{{ $task->status->label() }}</flux:badge>
                        </div>
                        <p class="mt-1 truncate text-sm font-medium">{{ $task->title }}</p>
                        @if ($lastEvent)
                            <p class="mt-0.5 text-xs text-zinc-400">
                                {{ $lastEvent->summary() }} · {{ $lastEvent->created_at->diffForHumans() }}
                            </p>
                        @endif
                    </div>
                    <flux:icon name="arrow-right" class="size-4 shrink-0 text-zinc-400" />
                </a>
            @endforeach
        </div>
    @endif
</div>
