<?php

namespace App\Models;

use App\Enums\DocumentScanStatus;
use Database\Factories\DocumentScanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DocumentScan extends Model
{
    public static function activeRunKey(int $id): string
    {
        return 'document-scan-run:'.$id;
    }

    /** @use HasFactory<DocumentScanFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'uploaded_by', 'original_filename', 'storage_path', 'mime_type', 'file_size',
        'status', 'gemini_model', 'document_type', 'document_title', 'summary',
        'readable', 'limitations', 'processing_error', 'processing_started_at',
        'processing_completed_at', 'extraction_status', 'extraction_error', 'record_plan', 'profile_schema_hash',
        'profile_status', 'profile_error', 'profile_unresolved', 'ai_responses',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DocumentScanStatus::class,
            'readable' => 'boolean',
            'processing_started_at' => 'datetime',
            'processing_completed_at' => 'datetime',
            'record_plan' => 'array',
            'profile_unresolved' => 'array',
            'ai_responses' => 'array',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function findings(): HasOne
    {
        return $this->hasOne(DocumentFinding::class);
    }

    public function extractedProfiles(): HasMany
    {
        return $this->hasMany(ExtractedProfile::class);
    }
}
