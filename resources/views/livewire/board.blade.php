<div @if (! $showDetail && ! $showCreate) wire:poll.{{ $this->pollInterval() }} @endif>
    <div class="flex items-center justify-between gap-3">
        <flux:button href="{{ route('overview') }}" variant="ghost" size="sm" icon="arrow-left" wire:navigate>
            Overview
        </flux:button>

        <div class="flex items-center gap-2">
            <flux:button href="{{ route('profiles.inbox', $profile) }}" variant="ghost" size="sm" icon="inbox" wire:navigate>
                Inbox
            </flux:button>
            <flux:button href="{{ route('profiles.activity', $profile) }}" variant="ghost" size="sm" icon="clock" wire:navigate>
                Activity
            </flux:button>
            <flux:button href="{{ route('profiles.settings', $profile) }}" variant="ghost" size="sm" icon="cog-6-tooth" wire:navigate>
                Settings
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

    @php
        $populated = [];
        $empty = [];
        foreach ($columns as $column) {
            if (($tasks[$column->value] ?? collect())->count() > 0) {
                $populated[] = $column;
            } else {
                $empty[] = $column;
            }
        }
    @endphp

    <div class="mt-8 flex items-start gap-4 overflow-x-auto pb-4" wire:ignore.self>
        @foreach ($populated as $column)
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
                            @if ($task->status === \App\Enums\TaskStatus::Ready)
                                @if ($task->readiness_score !== null)
                                    @php($light = $task->readiness_detail['light'] ?? 'red')
                                    <div class="mt-2 flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                                        <span @class([
                                            'inline-flex h-2 w-2 rounded-full',
                                            'bg-green-500' => $light === 'green',
                                            'bg-amber-500' => $light === 'amber',
                                            'bg-red-500' => $light === 'red',
                                        ])></span>
                                        Readiness {{ $task->readiness_score }}
                                    </div>
                                @else
                                    <div class="mt-2 flex items-center gap-1.5 text-xs text-zinc-400 dark:text-zinc-500" data-readiness="unscored">
                                        <span class="inline-flex h-2 w-2 rounded-full bg-zinc-300 dark:bg-zinc-600"></span>
                                        Unscored
                                    </div>
                                @endif
                            @endif
                            @if ($task->status === \App\Enums\TaskStatus::Processing)
                                <div class="mt-2 flex items-center gap-1.5 text-xs text-blue-600 dark:text-blue-400">
                                    <span class="relative flex h-2 w-2">
                                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-blue-400 opacity-75"></span>
                                        <span class="relative inline-flex h-2 w-2 rounded-full bg-blue-500"></span>
                                    </span>
                                    Running
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        @if (count($empty) > 0)
            <div class="flex w-56 shrink-0 flex-col">
                <div class="flex items-center gap-2 pt-2">
                    <span class="text-xs font-medium uppercase tracking-wide text-zinc-400">Empty</span>
                    <span class="text-xs text-zinc-400">{{ count($empty) }}</span>
                </div>
                <div class="mt-3 space-y-2">
                    @foreach ($empty as $column)
                        <div
                            class="flex min-h-10 items-center justify-between gap-2 rounded-lg border border-dashed border-zinc-200 bg-zinc-100/50 px-3 py-2 transition hover:border-zinc-300 dark:border-zinc-700/70 dark:bg-zinc-800/30"
                            data-board-column
                            data-status="{{ $column->value }}"
                        >
                            <flux:badge size="sm" color="{{ $column->color() }}">{{ $column->label() }}</flux:badge>
                            <span class="text-xs text-zinc-400">0</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- Create task (full-screen flyout, matching the detail view) --}}
    <flux:modal
        variant="flyout"
        position="right"
        wire:model.self="showCreate"
        name="create-task"
        class="w-screen max-w-none border-0 p-0"
    >
        <form wire:submit="createTask" class="flex h-dvh flex-col">
            <div class="border-b border-zinc-200 px-6 py-4 pe-16 dark:border-zinc-700">
                <flux:heading size="lg">New task</flux:heading>
                <flux:subheading>Starts in Backlog — scope it with an agent next.</flux:subheading>
            </div>

            <div class="flex-1 overflow-y-auto px-6 py-6">
                <div class="mx-auto w-full max-w-2xl space-y-6">
                    <flux:input wire:model="title" label="Title" placeholder="Short summary of the work" />
                    <flux:textarea wire:model="summary" label="Summary" rows="12" placeholder="What needs doing, context, links…" />
                    <flux:input wire:model="priority" type="number" min="1" max="100" label="Priority (1–100)" class="max-w-40" />
                </div>
            </div>

            <div class="flex justify-end gap-2 border-t border-zinc-200 px-6 py-4 dark:border-zinc-700">
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
        wire:model.self="showDetail"
        name="task-drawer"
        class="w-screen max-w-none border-0 p-0"
    >
        @if ($selectedTask)
            <div class="flex h-dvh flex-col">
                {{-- Header --}}
                <div class="shrink-0 border-b border-zinc-200 px-6 py-4 pe-16 dark:border-zinc-700">
                    <span class="font-mono text-xs text-zinc-400">{{ $selectedTask->ref }}</span>
                    <flux:heading size="lg">{{ $selectedTask->title }}</flux:heading>
                </div>

                {{-- Body: main + sidebar --}}
                <div class="grid flex-1 grid-cols-1 gap-6 overflow-y-auto p-6 lg:grid-cols-[minmax(0,2fr)_minmax(20rem,1fr)] lg:gap-8 lg:overflow-hidden">
                    {{-- Main column --}}
                    <div class="space-y-6 lg:overflow-y-auto lg:pe-2">
                        <form wire:submit="updateTask" class="space-y-4">
                            <flux:input wire:model="editTitle" label="Title" />
                            <flux:textarea wire:model="editSummary" label="Summary" rows="6" />
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <flux:input wire:model="editPriority" type="number" min="1" max="100" label="Priority (1–100)" />
                                <flux:select wire:model="editStatus" label="Status">
                                    @foreach ($statuses as $status)
                                        <flux:select.option value="{{ $status->value }}">{{ $status->label() }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>

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

                        @if ($selectedTask->status === \App\Enums\TaskStatus::Ready)
                            <div class="space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                                <div class="flex items-center justify-between gap-2">
                                    <flux:subheading>Readiness</flux:subheading>
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="sparkles"
                                        wire:click="scoreTask"
                                    >
                                        {{ $selectedTask->readiness_score !== null ? 'Re-score' : 'Score readiness' }}
                                    </flux:button>
                                </div>

                                @if ($selectedTask->readiness_score !== null)
                                    @php($light = $selectedTask->readiness_detail['light'] ?? 'red')
                                    <div class="flex items-center gap-2">
                                        <span @class([
                                            'inline-flex h-3 w-3 rounded-full',
                                            'bg-green-500' => $light === 'green',
                                            'bg-amber-500' => $light === 'amber',
                                            'bg-red-500' => $light === 'red',
                                        ])></span>
                                        <span class="text-lg font-semibold">{{ $selectedTask->readiness_score }}</span>
                                        <span class="text-sm text-zinc-400">/ 100</span>
                                    </div>

                                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                        @foreach (($selectedTask->readiness_detail['reviewers'] ?? []) as $reviewer)
                                            <div wire:key="reviewer-{{ $reviewer['agent'] }}" class="rounded-md bg-zinc-50 p-2 text-sm dark:bg-zinc-800/50">
                                                <div class="flex items-center justify-between gap-2">
                                                    <span class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $reviewer['agent'] }}</span>
                                                    <span class="text-xs font-medium">{{ $reviewer['score'] ?? '—' }}</span>
                                                </div>
                                                @if (! empty($reviewer['error']))
                                                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $reviewer['error'] }}</p>
                                                @else
                                                    @if (! empty($reviewer['summary']))
                                                        <p class="mt-1 text-zinc-600 dark:text-zinc-300">{{ $reviewer['summary'] }}</p>
                                                    @endif
                                                    @if (! empty($reviewer['blockers']))
                                                        <ul class="mt-1 list-inside list-disc text-xs text-zinc-500 dark:text-zinc-400">
                                                            @foreach ($reviewer['blockers'] as $blocker)
                                                                <li>{{ $blocker }}</li>
                                                            @endforeach
                                                        </ul>
                                                    @endif
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex h-3 w-3 rounded-full bg-zinc-300 dark:bg-zinc-600"></span>
                                        <span class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Unscored</span>
                                    </div>
                                    <p class="text-xs text-zinc-400">Not yet scored. Score the brief before auto-dispatch.</p>
                                @endif

                                <flux:button
                                    variant="primary"
                                    icon="play"
                                    class="w-full"
                                    wire:click="dispatchTask"
                                    wire:confirm="Dispatch a worker for this task now?"
                                >
                                    Dispatch
                                </flux:button>
                            </div>
                        @elseif ($selectedTask->status === \App\Enums\TaskStatus::Processing)
                            <div class="flex items-center gap-2 border-t border-zinc-200 pt-4 text-sm text-blue-600 dark:border-zinc-700 dark:text-blue-400">
                                <span class="relative flex h-2 w-2">
                                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-blue-400 opacity-75"></span>
                                    <span class="relative inline-flex h-2 w-2 rounded-full bg-blue-500"></span>
                                </span>
                                Worker running…
                            </div>
                        @elseif ($selectedTask->status === \App\Enums\TaskStatus::Review)
                            <div class="space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                                <flux:subheading>Review</flux:subheading>
                                @php($latestRun = $selectedTask->runs->first())
                                @if ($latestRun && $latestRun->summary)
                                    <pre class="max-h-[50vh] overflow-auto whitespace-pre-wrap rounded bg-zinc-50 p-3 text-xs text-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-300">{{ $latestRun->summary }}</pre>
                                @endif
                                <flux:textarea wire:model="reviewNote" label="Note (optional)" rows="3" placeholder="What needs changing?" />
                                <div class="flex justify-end gap-2">
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="arrow-uturn-left"
                                        wire:click="requestChanges"
                                    >
                                        Request changes
                                    </flux:button>
                                    <flux:button
                                        variant="primary"
                                        size="sm"
                                        icon="check"
                                        wire:click="approveTask"
                                    >
                                        Approve
                                    </flux:button>
                                </div>
                            </div>
                        @elseif ($selectedTask->status === \App\Enums\TaskStatus::Blocked)
                            <div class="border-t border-zinc-200 pt-4 dark:border-zinc-700">
                                <flux:button
                                    variant="primary"
                                    icon="arrow-path"
                                    class="w-full"
                                    wire:click="retryTask"
                                    wire:confirm="Move this task back to Ready for another attempt?"
                                >
                                    Retry
                                </flux:button>
                            </div>
                        @elseif (in_array($selectedTask->status, [\App\Enums\TaskStatus::Backlog, \App\Enums\TaskStatus::Research], true))
                            <div class="border-t border-zinc-200 pt-4 dark:border-zinc-700">
                                <flux:button
                                    variant="primary"
                                    icon="sparkles"
                                    class="w-full"
                                    wire:click="scopeTask"
                                >
                                    Scope with agent
                                </flux:button>
                                <p class="mt-2 text-xs text-zinc-400">Runs a read-only session to refine the brief or ask clarifying questions.</p>
                            </div>
                        @elseif ($selectedTask->status === \App\Enums\TaskStatus::Scoping)
                            <div class="flex items-center gap-2 border-t border-zinc-200 pt-4 text-sm text-indigo-600 dark:border-zinc-700 dark:text-indigo-400">
                                <span class="relative flex h-2 w-2">
                                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-indigo-400 opacity-75"></span>
                                    <span class="relative inline-flex h-2 w-2 rounded-full bg-indigo-500"></span>
                                </span>
                                Scoping…
                            </div>
                        @elseif ($selectedTask->status === \App\Enums\TaskStatus::NeedsInput)
                            <div class="space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                                <flux:subheading>Scoping conversation</flux:subheading>
                                <div class="max-h-[50vh] space-y-2 overflow-y-auto">
                                    @foreach ($selectedTask->comments->sortBy('id') as $comment)
                                        <div
                                            wire:key="thread-{{ $comment->id }}"
                                            @class([
                                                'rounded-md p-3 text-sm',
                                                'bg-indigo-50 text-indigo-900 dark:bg-indigo-950/40 dark:text-indigo-200' => $comment->isAgent(),
                                                'bg-zinc-50 text-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-300' => ! $comment->isAgent(),
                                            ])
                                        >
                                            <span class="text-xs font-medium uppercase tracking-wide opacity-70">{{ $comment->isAgent() ? 'Agent' : 'You' }}</span>
                                            <p class="mt-0.5 whitespace-pre-wrap">{{ $comment->body }}</p>
                                        </div>
                                    @endforeach
                                </div>
                                <flux:textarea wire:model="scopeAnswer" label="Your answer" rows="4" placeholder="Answer the questions to continue scoping" />
                                <div class="flex justify-end">
                                    <flux:button
                                        variant="primary"
                                        size="sm"
                                        icon="paper-airplane"
                                        wire:click="continueScoping"
                                    >
                                        Send &amp; continue scoping
                                    </flux:button>
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Sidebar column --}}
                    <div class="space-y-6 lg:overflow-y-auto lg:border-s lg:border-zinc-200 lg:ps-6 dark:lg:border-zinc-700">
                        @if ($selectedTask->runs->isNotEmpty())
                            <div class="space-y-3">
                                <flux:subheading>Runs</flux:subheading>
                                @foreach ($selectedTask->runs as $run)
                                    <details wire:key="run-{{ $run->id }}" class="rounded-md border border-zinc-200 bg-zinc-50 p-3 text-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                                        <summary class="flex cursor-pointer items-center justify-between gap-2">
                                            <span class="flex items-center gap-2">
                                                <span class="font-mono text-xs text-zinc-400">#{{ $run->attempt }}</span>
                                                <flux:badge size="sm" color="{{ $run->outcome === 'success' ? 'green' : ($run->outcome === 'failed' ? 'red' : 'amber') }}">
                                                    {{ $run->outcome ?? 'running' }}
                                                </flux:badge>
                                            </span>
                                            <span class="text-xs text-zinc-400">
                                                {{ $run->cost !== null ? '£'.number_format((float) $run->cost, 4) : '' }}
                                                {{ $run->token_count !== null ? '· '.number_format($run->token_count).' tok' : '' }}
                                            </span>
                                        </summary>
                                        <div class="mt-2 space-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                                            @if ($run->started_at)
                                                <div>Started {{ $run->started_at->format('d/m/Y H:i') }}</div>
                                            @endif
                                            @if ($run->finished_at)
                                                <div>Finished {{ $run->finished_at->format('d/m/Y H:i') }} {{ $run->durationSeconds() !== null ? '('.$run->durationSeconds().'s)' : '' }}</div>
                                            @endif
                                            @if ($run->session_id)
                                                <div class="font-mono">session {{ $run->session_id }}</div>
                                            @endif
                                        </div>
                                        @if ($run->summary)
                                            <pre class="mt-2 max-h-[60vh] overflow-auto whitespace-pre-wrap rounded bg-white p-3 text-xs text-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">{{ $run->summary }}</pre>
                                        @endif
                                    </details>
                                @endforeach
                            </div>
                        @endif

                        @if ($selectedTask->comments->isNotEmpty())
                            <div class="space-y-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                                <flux:subheading>Notes</flux:subheading>
                                @foreach ($selectedTask->comments as $comment)
                                    <div wire:key="comment-{{ $comment->id }}" class="rounded-md bg-zinc-50 p-2 text-sm text-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-300">
                                        <p class="whitespace-pre-wrap">{{ $comment->body }}</p>
                                        <span class="text-xs text-zinc-400">{{ $comment->isAgent() ? 'Agent' : 'Human' }} · {{ $comment->created_at->format('d/m/Y H:i') }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif

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
                </div>
            </div>
        @endif
    </flux:modal>
</div>
