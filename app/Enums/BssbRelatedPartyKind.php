<?php

namespace App\Enums;

enum BssbRelatedPartyKind: string
{
    case Staff = 'staff';
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'BSSB Director / Employee / DR',
            self::Client => 'BSSB Client',
        };
    }

    public function complianceKind(): string
    {
        return match ($this) {
            self::Staff => 'bssb_staff',
            self::Client => 'bssb_client',
        };
    }
}
