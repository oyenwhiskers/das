<?php

namespace App\Enums;

enum AttachmentScanStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Attached = 'attached';
    case NeedsReview = 'needs_review';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Attached => 'Attached',
            self::NeedsReview => 'Needs review',
            self::Failed => 'Failed',
        };
    }
}
