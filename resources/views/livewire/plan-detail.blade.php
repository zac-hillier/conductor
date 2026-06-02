<div class="mx-auto max-w-4xl">
    <div class="flex items-center justify-between gap-3">
        <flux:button href="{{ route('profiles.plans', $profile) }}" variant="ghost" size="sm" icon="arrow-left" wire:navigate>
            Plans
        </flux:button>
        <div class="flex items-center gap-2">
            @if ($plan->status === \App\Enums\PlanStatus::Drafting)
                <flux:button variant="primary" size="sm" icon="sparkles" wire:click="generate" wire:confirm="Generate phases for this plan with the planning agent?">Generate phases</flux:button>
            @endif
            <flux:badge size="sm" color="{{ $plan->status->color() }}">{{ $plan->status->label() }}</flux:badge>
            <flux:button variant="ghost" size="sm" icon="trash" wire:click="deletePlan" wire:confirm="Delete this plan and its phases? Backing tasks are kept.">Delete</flux:button>
        </div>
    </div>

    <div class="mt-4">
        <flux:heading size="xl">{{ $plan->name }}</flux:heading>
        <flux:subheading>{{ $plan->project->name }} · {{ $phases->count() }} {{ Str::plural('phase', $phases->count()) }}@if ($plan->costRollup() > 0) · £{{ number_format($plan->costRollup(), 4) }}@endif</flux:subheading>
    </div>

    @if ($plan->concept)
        <div class="mt-4 rounded-md bg-zinc-50 p-3 text-sm text-zinc-600 dark:bg-zinc-800/50 dark:text-zinc-300">
            <p class="whitespace-pre-wrap">{{ $plan->concept }}</p>
        </div>
    @endif

    @if ($plan->artifact_dir)
        <p class="mt-2 font-mono text-xs text-zinc-400">artifacts: {{ $plan->artifact_dir }}</p>
    @endif

    {{-- Dashboard: phase progress + project pulse --}}
    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-md border border-zinc-200 p-3 dark:border-zinc-700">
            <p class="text-xs text-zinc-400">Progress</p>
            <p class="mt-1 text-lg font-semibold">{{ $doneCount }} / {{ $phases->count() }}</p>
            @if ($phases->count() > 0)
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                    <div class="h-full bg-green-500" style="width: {{ round($doneCount / max($phases->count(), 1) * 100) }}%"></div>
                </div>
            @endif
        </div>
        @if ($pulse['open_p0'] > 0)
            <div class="rounded-md border border-red-200 p-3 dark:border-red-900/50">
                <p class="text-xs text-zinc-400">Open blockers</p>
                <p class="mt-1 text-lg font-semibold text-red-600 dark:text-red-400">{{ $pulse['open_p0'] }} P0</p>
            </div>
        @endif
        @if ($pulse['changelog'])
            <div class="rounded-md border border-zinc-200 p-3 dark:border-zinc-700">
                <p class="text-xs text-zinc-400">Latest</p>
                <p class="mt-1 truncate text-sm text-zinc-600 dark:text-zinc-300">{{ $pulse['changelog'] }}</p>
            </div>
        @endif
    </div>

    {{-- Phases --}}
    <div class="mt-8 space-y-3">
        <flux:subheading>Phases</flux:subheading>
        @forelse ($phases as $phase)
            <div wire:key="phase-{{ $phase->id }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="font-mono text-xs text-zinc-400">#{{ $phase->number }}</span>
                            <span class="font-medium">{{ $phase->name }}</span>
                            <flux:badge size="sm" color="{{ $phase->status->color() }}">{{ $phase->status->label() }}</flux:badge>
                        </div>
                        @if ($phase->objective)
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $phase->objective }}</p>
                        @endif
                        @if ($phase->task)
                            <p class="mt-1 text-xs text-zinc-400">task {{ $phase->task->ref }} — {{ $phase->task->status->label() }}</p>
                        @endif
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        @if ($phase->status === \App\Enums\PhaseStatus::Blocked)
                            <flux:button variant="primary" size="sm" icon="arrow-path" wire:click="retryPhase({{ $phase->id }})" wire:confirm="Retry this blocked phase with a fresh task?">Retry</flux:button>
                        @elseif ($phase->task)
                            <flux:button href="{{ route('profiles.board', $profile) }}" variant="ghost" size="sm" icon="arrow-up-right" wire:navigate>On board</flux:button>
                        @elseif ($phase->isExecutable())
                            <flux:button variant="primary" size="sm" icon="play" wire:click="makeExecutable({{ $phase->id }})">Start phase</flux:button>
                        @else
                            <span class="text-xs text-zinc-400">awaits earlier phases</span>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <p class="text-sm text-zinc-400">No phases yet.</p>
        @endforelse
    </div>

    {{-- Add a phase --}}
    <form wire:submit="addPhase" class="mt-8 space-y-4 border-t border-zinc-200 pt-6 dark:border-zinc-700">
        <flux:heading size="lg">Add a phase</flux:heading>
        <flux:input wire:model="phaseName" label="Name" placeholder="e.g. Database foundation" />
        <flux:textarea wire:model="phaseObjective" label="Objective" rows="3" placeholder="What this phase delivers" />
        <div class="flex justify-end">
            <flux:button type="submit" variant="primary">Add phase</flux:button>
        </div>
    </form>
</div>
