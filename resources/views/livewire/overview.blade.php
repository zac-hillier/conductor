<div>
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">Conductor</flux:heading>
            <flux:subheading>Profiles overview</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="$set('showCreate', true)">
            New profile
        </flux:button>
    </div>

    @if ($profiles->isEmpty())
        <flux:callout class="mt-10" icon="folder-plus">
            <flux:callout.heading>No profiles yet</flux:callout.heading>
            <flux:callout.text>
                Create your first profile to start organising work on its board.
            </flux:callout.text>
            <x-slot name="actions">
                <flux:button size="sm" wire:click="$set('showCreate', true)">New profile</flux:button>
            </x-slot>
        </flux:callout>
    @else
        <div class="mt-10 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($profiles as $profile)
                @php
                    $counts = $profile->tasks->countBy(fn ($task) => $task->status->value);
                    $attention = collect(\App\Livewire\Inbox::ATTENTION_STATUSES)
                        ->sum(fn ($status) => $counts->get($status->value, 0));
                @endphp
                <flux:card class="space-y-4" data-profile="{{ $profile->slug }}" data-attention="{{ $attention }}">
                    <div class="flex items-start justify-between gap-3">
                        <flux:heading size="lg">{{ $profile->name }}</flux:heading>
                        <div class="flex items-center gap-2">
                            @if ($attention > 0)
                                <flux:badge size="sm" color="amber" icon="inbox">{{ $attention }}</flux:badge>
                            @endif
                            <flux:badge size="sm" color="{{ $profile->kind === \App\Enums\ProfileKind::Customer ? 'blue' : 'purple' }}">
                                {{ $profile->kind->label() }}
                            </flux:badge>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($statuses as $status)
                            <flux:badge size="sm" color="{{ $status->color() }}">
                                {{ $status->label() }}: {{ $counts->get($status->value, 0) }}
                            </flux:badge>
                        @endforeach
                    </div>

                    <div class="flex items-center gap-2">
                        <flux:button
                            href="{{ route('profiles.board', $profile) }}"
                            variant="ghost"
                            size="sm"
                            icon-trailing="arrow-right"
                            wire:navigate
                        >
                            Open board
                        </flux:button>
                        <flux:button
                            href="{{ route('profiles.inbox', $profile) }}"
                            variant="ghost"
                            size="sm"
                            icon="inbox"
                            wire:navigate
                        >
                            Inbox
                        </flux:button>
                    </div>
                </flux:card>
            @endforeach
        </div>
    @endif

    <flux:modal wire:model.self="showCreate" name="create-profile" class="md:w-96">
        <form wire:submit="createProfile" class="space-y-6">
            <div>
                <flux:heading size="lg">New profile</flux:heading>
                <flux:subheading>The slug is generated from the name.</flux:subheading>
            </div>

            <flux:input wire:model="name" label="Name" placeholder="Acme Ltd" />

            <flux:select wire:model="kind" label="Kind">
                @foreach ($kinds as $kindOption)
                    <flux:select.option value="{{ $kindOption->value }}">{{ $kindOption->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Create profile</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
