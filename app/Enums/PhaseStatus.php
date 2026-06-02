<?php

namespace App\Enums;

enum PhaseStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case InProgress = 'in_progress';
    case Review = 'review';
    case Done = 'done';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Ready => 'Ready',
            self::InProgress => 'In Progress',
            self::Review => 'Review',
            self::Done => 'Done',
            self::Blocked => 'Blocked',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'zinc',
            self::Ready => 'cyan',
            self::InProgress => 'blue',
            self::Review => 'purple',
            self::Done => 'green',
            self::Blocked => 'red',
        };
    }
}
