<?php

namespace App\Models;

use App\Enums\AttachmentScanStatus;
use App\Enums\AttachmentType;
use Database\Factories\AttachmentScanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttachmentScan extends Model
{
    /** @use HasFactory<AttachmentScanFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'uploaded_by',
        'original_filename',
        'storage_path',
        'mime_type',
        'file_size',
        'status',
        'gemini_model',
        'attachment_type',
        'extracted_name',
        'extracted_identification_number',
        'customer_id',
        'matched_by',
        'attached_at',
        'processing_error',
        'processing_started_at',
        'processing_completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AttachmentScanStatus::class,
            'attachment_type' => AttachmentType::class,
            'attached_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'processing_completed_at' => 'datetime',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function matcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }
}
