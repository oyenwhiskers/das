<?php

namespace App\Enums;

enum OccupancyStatus: string
{
    case Owned = 'owned';
    case Rented = 'rented';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Owned => 'Owned',
            self::Rented => 'Rented',
            self::Other => 'Other',
        };
    }
}
