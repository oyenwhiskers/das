<?php

namespace App\Enums;

enum AddressType: string
{
    case Residential = 'residential';
    case Mailing = 'mailing';
    case Registered = 'registered';

    public function label(): string
    {
        return match ($this) {
            self::Residential => 'Residential Address',
            self::Mailing => 'Mailing Address',
            self::Registered => 'Registered Address',
        };
    }
}
