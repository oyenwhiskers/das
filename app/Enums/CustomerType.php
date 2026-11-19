<?php

namespace App\Enums;

enum CustomerType: string
{
    case Individual = 'individual';
    case Corporate = 'corporate';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Individual',
            self::Corporate => 'Corporate',
        };
    }
}
