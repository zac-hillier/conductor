<div class="mx-auto max-w-7xl">
    <div class="flex items-center justify-between gap-3">
        <flux:button href="{{ route('profiles.board', $profile) }}" variant="ghost" size="sm" icon="arrow-left" wire:navigate>
            Board
        </flux:button>
    </div>

    <div class="mt-4">
        <flux:heading size="xl">{{ $profile->name }}</flux:heading>
        <flux:subheading>Activity</flux:subheading>
    </div>

    <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-end">
        <flux:input
            wire:model.live.debounce.300ms="search"
            label="Search"
            placeholder="Ref, title or kind"
            icon="magnifying-glass"
            class="sm:max-w-xs"
        />

        <flux:select wire:model.live="kind" label="Kind" class="sm:max-w-xs">
            <flux:select.option value="">All kinds</flux:select.option>
            @foreach ($kinds as $kindOption)
                <flux:select.option value="{{ $kindOption }}">{{ ucfirst(str_replace('_', ' ', $kindOption)) }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="mt-6 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-100 text-left text-xs uppercase text-zinc-500 dark:bg-zinc-800">
                <tr>
                    <th class="px-4 py-2 font-medium">Task</th>
                    <th class="px-4 py-2 font-medium">Event</th>
                    <th class="px-4 py-2 font-medium">When</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($events as $event)
                    <tr wire:key="event-{{ $event->id }}" class="bg-white dark:bg-zinc-900">
                        <td class="px-4 py-2">
                            <span class="font-mono text-xs text-zinc-400">{{ $event->task?->ref }}</span>
                            <span class="ml-2">{{ \Illuminate\Support\Str::limit($event->task?->title, 40) }}</span>
                        </td>
                        <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">{{ $event->summary() }}</td>
                        <td class="px-4 py-2 text-xs text-zinc-400">{{ $event->created_at->format('d/m/Y H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-8 text-center text-zinc-400">No activity found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
