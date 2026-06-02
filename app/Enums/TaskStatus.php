<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Backlog = 'backlog';
    case Research = 'research';
    case Scoping = 'scoping';
    case NeedsInput = 'needs_input';
    case Ready = 'ready';
    case Processing = 'processing';
    case Blocked = 'blocked';
    case Review = 'review';
    case Complete = 'complete';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Backlog => 'Backlog',
            self::Research => 'Research',
            self::Scoping => 'Scoping',
            self::NeedsInput => 'Needs Input',
            self::Ready => 'Ready',
            self::Processing => 'Processing',
            self::Blocked => 'Blocked',
            self::Review => 'Review',
            self::Complete => 'Complete',
            self::Archived => 'Archived',
        };
    }

    /**
     * A one-line, human-facing explanation of what this status means and what
     * (if anything) is happening in the background — surfaced in the drawer so
     * the human always knows what the coordinator is doing.
     */
    public function description(): string
    {
        return match ($this) {
            self::Backlog => 'Parked — not yet scoped.',
            self::Research => 'Gathering understanding before scoping.',
            self::Scoping => 'An agent is interrogating this task to define it.',
            self::NeedsInput => 'Waiting for your answer to continue scoping.',
            self::Ready => 'Defined and eligible for dispatch.',
            self::Processing => 'A worker is executing this task now.',
            self::Blocked => 'Stuck — needs your attention before it can continue.',
            self::Review => 'Worker finished — awaiting your sign-off.',
            self::Complete => 'Done.',
            self::Archived => 'Archived.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Backlog => 'zinc',
            self::Research => 'sky',
            self::Scoping => 'indigo',
            self::NeedsInput => 'amber',
            self::Ready => 'cyan',
            self::Processing => 'blue',
            self::Blocked => 'red',
            self::Review => 'purple',
            self::Complete => 'green',
            self::Archived => 'zinc',
        };
    }

    /**
     * @return array<int, self>
     */
    public static function ordered(): array
    {
        return [
            self::Backlog,
            self::Research,
            self::Scoping,
            self::NeedsInput,
            self::Ready,
            self::Processing,
            self::Blocked,
            self::Review,
            self::Complete,
            self::Archived,
        ];
    }
}
