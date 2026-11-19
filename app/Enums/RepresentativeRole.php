<?php

namespace App\Enums;

enum RepresentativeRole: string
{
    case PrimaryContact = 'primary_contact';
    case AuthorizedSignatory = 'authorized_signatory';

    public function label(): string
    {
        return match ($this) {
            self::PrimaryContact => 'Primary Contact',
            self::AuthorizedSignatory => 'Authorized Signatory',
        };
    }
}
