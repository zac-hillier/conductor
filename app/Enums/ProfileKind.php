<?php

namespace App\Enums;

enum ProfileKind: string
{
    case Customer = 'customer';
    case Personal = 'personal';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Personal => 'Personal',
        };
    }
}
