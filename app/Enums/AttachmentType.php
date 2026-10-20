<?php

namespace App\Enums;

enum AttachmentType: string
{
    case IcPdf = 'ic_pdf';
    case Signature = 'signature';
    case CdsPhillip = 'cds_phillip';
    case CdsBimbsec = 'cds_bimbsec';

    public function label(): string
    {
        return match ($this) {
            self::IcPdf => 'IC PDF',
            self::Signature => 'Signature',
            self::CdsPhillip => 'CDS Maintenance (Phillip)',
            self::CdsBimbsec => 'CDS Maintenance (BIMBSEC)',
        };
    }
}
