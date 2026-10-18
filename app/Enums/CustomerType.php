<?php

namespace App\Enums;

enum CustomerType: string
{
    case Individual = 'individual';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Individual',
        };
    }
}
