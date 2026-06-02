<div class="mx-auto max-w-4xl">
    <div class="flex items-center justify-between gap-3">
        <flux:button href="{{ route('profiles.board', $profile) }}" variant="ghost" size="sm" icon="arrow-left" wire:navigate>
            Board
        </flux:button>
        <flux:button variant="primary" size="sm" icon="plus" wire:click="$set('showCreate', true)">
            New plan
        </flux:button>
    </div>

    <div class="mt-4">
        <flux:heading size="xl">{{ $profile->name }}</flux:heading>
        <flux:subheading>Plans</flux:subheading>
    </div>

    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
        A plan decomposes a piece of work into coordinated phases. Phases run as tasks on the board.
    </p>

    <div class="mt-8 space-y-3">
        @forelse ($plans as $plan)
            <a
                href="{{ route('profiles.plans.show', [$profile, $plan]) }}"
                wire:navigate
                wire:key="plan-{{ $plan->id }}"
                class="block rounded-lg border border-zinc-200 p-4 transition hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600"
            >
                <div class="flex items-center justify-between gap-3">
                    <span class="font-medium">{{ $plan->name }}</span>
                    <flux:badge size="sm" color="{{ $plan->status->color() }}">{{ $plan->status->label() }}</flux:badge>
                </div>
                <div class="mt-1 flex items-center gap-3 text-xs text-zinc-400">
                    <span>{{ $plan->project->name }}</span>
                    <span>{{ $plan->phases->count() }} {{ Str::plural('phase', $plan->phases->count()) }}</span>
                    @if ($plan->cost !== null)
                        <span>£{{ number_format((float) $plan->cost, 4) }}</span>
                    @endif
                </div>
            </a>
        @empty
            <p class="text-sm text-zinc-400">No plans yet. Create one to decompose a build into phases.</p>
        @endforelse
    </div>

    {{-- Create plan --}}
    <flux:modal wire:model.self="showCreate" name="create-plan" class="max-w-lg">
        <form wire:submit="createPlan" class="space-y-6">
            <div>
                <flux:heading size="lg">New plan</flux:heading>
                <flux:subheading>Describe the work; phases come next.</flux:subheading>
            </div>

            <flux:input wire:model="name" label="Name" placeholder="e.g. Returns reason filter" />
            <flux:textarea wire:model="concept" label="Concept" rows="6" placeholder="What is this build? Goals, constraints, context…" />
            @if ($projects->count() > 1)
                <flux:select wire:model="projectId" label="Project">
                    @foreach ($projects as $project)
                        <flux:select.option value="{{ $project->id }}">{{ $project->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Create plan</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
