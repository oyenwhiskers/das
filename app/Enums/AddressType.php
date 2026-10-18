<?php

namespace App\Enums;

enum AddressType: string
{
    case Residential = 'residential';
    case Mailing = 'mailing';

    public function label(): string
    {
        return match ($this) {
            self::Residential => 'Residential Address',
            self::Mailing => 'Mailing Address',
        };
    }
}
