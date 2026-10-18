<?php

namespace App\Enums;

enum ContactType: string
{
    case Mobile = 'mobile';
    case Home = 'home';
    case Email = 'email';
    case Social = 'social';

    public function label(): string
    {
        return match ($this) {
            self::Mobile => 'Mobile Number',
            self::Home => 'Home Telephone',
            self::Email => 'Email Address',
            self::Social => 'Social Media',
        };
    }
}
