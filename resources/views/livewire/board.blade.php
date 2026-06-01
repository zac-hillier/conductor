<div>
    <div class="flex items-center justify-between gap-3">
        <flux:button href="{{ route('overview') }}" variant="ghost" size="sm" icon="arrow-left" wire:navigate>
            Overview
        </flux:button>

        <div class="flex items-center gap-2">
            <flux:button href="{{ route('profiles.activity', $profile) }}" variant="ghost" size="sm" icon="clock" wire:navigate>
                Activity
            </flux:button>
            <flux:button variant="primary" size="sm" icon="plus" wire:click="$set('showCreate', true)">
                New task
            </flux:button>
        </div>
    </div>

    <div class="mt-4">
        <flux:heading size="xl">{{ $profile->name }}</flux:heading>
        <flux:subheading>Board</flux:subheading>
    </div>

    <div class="mt-8 flex gap-4 overflow-x-auto pb-4" wire:ignore.self>
        @foreach ($columns as $column)
            <div class="flex w-72 shrink-0 flex-col">
                <div class="flex items-center gap-2 pt-2">
                    <flux:badge size="sm" color="{{ $column->color() }}">{{ $column->label() }}</flux:badge>
                    <span class="text-xs text-zinc-400">{{ ($tasks[$column->value] ?? collect())->count() }}</span>
                </div>
                <div
                    class="mt-3 min-h-32 space-y-2 rounded-lg bg-zinc-100/70 p-2 dark:bg-zinc-800/50"
                    data-board-column
                    data-status="{{ $column->value }}"
                >
                    @foreach ($tasks[$column->value] ?? [] as $task)
                        <div
                            wire:key="task-{{ $task->id }}"
                            data-task-id="{{ $task->id }}"
                            wire:click="selectTask({{ $task->id }})"
                            class="cursor-pointer rounded-md border border-zinc-200 bg-white p-3 shadow-sm transition hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-mono text-xs text-zinc-400">{{ $task->ref }}</span>
                                <flux:badge size="sm" color="{{ $task->priority >= 70 ? 'red' : ($task->priority >= 40 ? 'amber' : 'zinc') }}">
                                    P{{ $task->priority }}
                                </flux:badge>
                            </div>
                            <p class="mt-1 truncate text-sm font-medium">{{ $task->title }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    {{-- Create task --}}
    <flux:modal wire:model.self="showCreate" name="create-task" class="md:w-96">
        <form wire:submit="createTask" class="space-y-6">
            <div>
                <flux:heading size="lg">New task</flux:heading>
                <flux:subheading>Starts in Backlog.</flux:subheading>
            </div>

            <flux:input wire:model="title" label="Title" placeholder="Short summary of the work" />
            <flux:textarea wire:model="summary" label="Summary" rows="3" />
            <flux:input wire:model="priority" type="number" min="1" max="100" label="Priority (1–100)" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Create task</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Card drawer --}}
    <flux:modal
        variant="flyout"
        position="right"
        :open="$selectedTask !== null"
        wire:model.self="selectedTaskId"
        name="task-drawer"
        class="w-full max-w-md"
    >
        @if ($selectedTask)
            <div class="space-y-6">
                <div>
                    <span class="font-mono text-xs text-zinc-400">{{ $selectedTask->ref }}</span>
                    <flux:heading size="lg">Task detail</flux:heading>
                </div>

                <form wire:submit="updateTask" class="space-y-4">
                    <flux:input wire:model="editTitle" label="Title" />
                    <flux:textarea wire:model="editSummary" label="Summary" rows="3" />
                    <flux:input wire:model="editPriority" type="number" min="1" max="100" label="Priority (1–100)" />

                    <flux:select wire:model="editStatus" label="Status">
                        @foreach ($statuses as $status)
                            <flux:select.option value="{{ $status->value }}">{{ $status->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <div class="flex justify-between gap-2 pt-2">
                        <flux:button
                            type="button"
                            variant="danger"
                            size="sm"
                            icon="trash"
                            wire:click="deleteTask"
                            wire:confirm="Delete this task and its history?"
                        >
                            Delete
                        </flux:button>
                        <flux:button type="submit" variant="primary" size="sm">Save changes</flux:button>
                    </div>
                </form>

                @if ($selectedTask->definition_of_done || $selectedTask->constraints || $selectedTask->target_paths)
                    <div class="space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                        @if ($selectedTask->definition_of_done)
                            <div>
                                <flux:subheading>Definition of done</flux:subheading>
                                <ul class="mt-1 list-inside list-disc text-sm text-zinc-600 dark:text-zinc-300">
                                    @foreach ($selectedTask->definition_of_done as $item)
                                        <li>{{ $item }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        @if ($selectedTask->constraints)
                            <div>
                                <flux:subheading>Constraints</flux:subheading>
                                <ul class="mt-1 list-inside list-disc text-sm text-zinc-600 dark:text-zinc-300">
                                    @foreach ($selectedTask->constraints as $item)
                                        <li>{{ $item }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        @if ($selectedTask->target_paths)
                            <div>
                                <flux:subheading>Target paths</flux:subheading>
                                <ul class="mt-1 list-inside list-disc font-mono text-xs text-zinc-600 dark:text-zinc-300">
                                    @foreach ($selectedTask->target_paths as $item)
                                        <li>{{ $item }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                @endif

                <div class="border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <flux:subheading>History</flux:subheading>
                    <ul class="mt-2 space-y-2">
                        @forelse ($selectedTask->events as $event)
                            <li wire:key="event-{{ $event->id }}" class="flex items-start justify-between gap-3 text-sm">
                                <span class="text-zinc-700 dark:text-zinc-300">{{ $event->summary() }}</span>
                                <span class="shrink-0 text-xs text-zinc-400">{{ $event->created_at->format('d/m/Y H:i') }}</span>
                            </li>
                        @empty
                            <li class="text-sm text-zinc-400">No history yet.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
