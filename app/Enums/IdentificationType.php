<?php

namespace App\Enums;

enum IdentificationType: string
{
    case Nric = 'nric';
    case Passport = 'passport';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Nric => 'NRIC',
            self::Passport => 'Passport',
            self::Other => 'Other',
        };
    }
}
