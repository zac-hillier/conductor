<?php

namespace App\Enums;

enum PlanStatus: string
{
    case Drafting = 'drafting';
    case Scouting = 'scouting';
    case Planning = 'planning';
    case Ready = 'ready';
    case Executing = 'executing';
    case Reviewing = 'reviewing';
    case Complete = 'complete';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Drafting => 'Drafting',
            self::Scouting => 'Scouting',
            self::Planning => 'Planning',
            self::Ready => 'Ready',
            self::Executing => 'Executing',
            self::Reviewing => 'Reviewing',
            self::Complete => 'Complete',
            self::Blocked => 'Blocked',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Drafting => 'zinc',
            self::Scouting => 'sky',
            self::Planning => 'indigo',
            self::Ready => 'cyan',
            self::Executing => 'blue',
            self::Reviewing => 'purple',
            self::Complete => 'green',
            self::Blocked => 'red',
        };
    }
}
