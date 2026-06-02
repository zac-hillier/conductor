<div class="mx-auto max-w-3xl">
    <div class="flex items-center justify-between gap-3">
        <flux:button href="{{ route('profiles.board', $profile) }}" variant="ghost" size="sm" icon="arrow-left" wire:navigate>
            Board
        </flux:button>
    </div>

    <div class="mt-4">
        <flux:heading size="xl">{{ $profile->name }}</flux:heading>
        <flux:subheading>Projects</flux:subheading>
    </div>

    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
        A profile can hold several projects, each with its own working directory. Tasks belong to one
        project and never cross between them. Leave a working directory blank to inherit the profile home.
    </p>

    {{-- Existing projects --}}
    <div class="mt-8 space-y-3">
        @foreach ($projects as $project)
            <div wire:key="project-{{ $project->id }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                @if ($editingId === $project->id)
                    <form wire:submit="saveProject" class="space-y-3">
                        <flux:input wire:model="editName" label="Name" />
                        <flux:input wire:model="editWorkdir" label="Working directory" placeholder="Inherits profile home if blank" />
                        <div class="flex justify-end gap-2">
                            <flux:button type="button" variant="ghost" size="sm" wire:click="cancelEdit">Cancel</flux:button>
                            <flux:button type="submit" variant="primary" size="sm">Save</flux:button>
                        </div>
                    </form>
                @else
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-medium">{{ $project->name }}</span>
                                @if ($project->is_default)
                                    <flux:badge size="sm" color="zinc">default</flux:badge>
                                @endif
                            </div>
                            <p class="mt-0.5 truncate font-mono text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $project->workdir ?: 'inherits profile home ('.($profile->workdir ?: 'unset').')' }}
                            </p>
                            <p class="mt-0.5 text-xs text-zinc-400">{{ $project->tasks_count }} {{ Str::plural('task', $project->tasks_count) }}</p>

                            @php($map = $project->context_map ?? [])
                            @if ($map)
                                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                    @foreach (['living_state' => 'green', 'docs' => 'sky', 'reference' => 'amber'] as $role => $colour)
                                        @php($count = count($map['roles'][$role] ?? []))
                                        @if ($count > 0)
                                            <flux:badge size="sm" color="{{ $colour }}">{{ str_replace('_', ' ', $role) }} ×{{ $count }}</flux:badge>
                                        @endif
                                    @endforeach
                                    @if (! empty($map['manifest']))
                                        <flux:badge size="sm" color="purple">manifest ({{ count($map['manifest']['repos'] ?? []) }} repos)</flux:badge>
                                    @endif
                                    @if (! empty($map['entry_doc']))
                                        <span class="text-xs text-zinc-400">entry: {{ basename($map['entry_doc']) }}</span>
                                    @endif
                                </div>

                                @php($pulse = $project->pulse())
                                @if ($pulse['changelog'] || $pulse['open_p0'] > 0 || $pulse['roadmap'])
                                    <div class="mt-2 space-y-0.5 text-xs">
                                        @if ($pulse['open_p0'] > 0)
                                            <p class="text-red-600 dark:text-red-400">{{ $pulse['open_p0'] }} open P0 {{ Str::plural('blocker', $pulse['open_p0']) }}</p>
                                        @endif
                                        @if ($pulse['roadmap'])
                                            <p class="text-zinc-500 dark:text-zinc-400">▸ {{ Str::limit($pulse['roadmap'], 80) }}</p>
                                        @endif
                                        @if ($pulse['changelog'])
                                            <p class="text-zinc-400">latest: {{ Str::limit($pulse['changelog'], 80) }}</p>
                                        @endif
                                    </div>
                                @endif
                            @endif
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <flux:button variant="ghost" size="sm" icon="arrow-path" wire:click="rescan({{ $project->id }})">Rescan</flux:button>
                            <flux:button variant="ghost" size="sm" icon="sparkles" wire:click="deepScan({{ $project->id }})" wire:confirm="Run an agent survey to propose a role map for this project?">Deep scan</flux:button>
                            <flux:button variant="ghost" size="sm" icon="pencil-square" wire:click="editProject({{ $project->id }})">Edit</flux:button>
                            @unless ($project->is_default)
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    wire:click="deleteProject({{ $project->id }})"
                                    wire:confirm="Delete this project? Its tasks stay but lose their project link."
                                >
                                    Delete
                                </flux:button>
                            @endunless
                        </div>
                    </div>

                    @php($proposal = ($project->settings ?? [])['context_proposal'] ?? null)
                    @if ($proposal)
                        <div class="mt-3 rounded-md border border-indigo-200 bg-indigo-50/50 p-3 dark:border-indigo-900/50 dark:bg-indigo-950/20">
                            <p class="text-sm font-medium text-indigo-700 dark:text-indigo-300">Proposed role map (from a deep scan) — confirm to apply</p>
                            @if (! empty($proposal['rationale']))
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $proposal['rationale'] }}</p>
                            @endif
                            <div class="mt-2 space-y-1 text-xs">
                                @foreach (['reference', 'docs', 'living_state'] as $role)
                                    @foreach ($proposal['roles'][$role] ?? [] as $path)
                                        <p><span class="font-medium">{{ str_replace('_', ' ', $role) }}:</span> <span class="font-mono text-zinc-500 dark:text-zinc-400">{{ $path }}</span></p>
                                    @endforeach
                                @endforeach
                            </div>
                            <div class="mt-3 flex justify-end gap-2">
                                <flux:button variant="ghost" size="sm" wire:click="discardProposal({{ $project->id }})">Discard</flux:button>
                                <flux:button variant="primary" size="sm" icon="check" wire:click="confirmProposal({{ $project->id }})">Confirm</flux:button>
                            </div>
                        </div>
                    @endif
                @endif
            </div>
        @endforeach
    </div>

    {{-- Add a project --}}
    <form wire:submit="createProject" class="mt-8 space-y-4 border-t border-zinc-200 pt-6 dark:border-zinc-700">
        <flux:heading size="lg">Add a project</flux:heading>
        <flux:input wire:model="newName" label="Name" placeholder="e.g. mbridge" />
        <flux:input wire:model="newWorkdir" label="Working directory" placeholder="/home/zac/shore/mbridge (blank = inherit profile home)" />
        <div class="flex justify-end">
            <flux:button type="submit" variant="primary">Add project</flux:button>
        </div>
    </form>
</div>
