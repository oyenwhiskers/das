<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExtractedProfile extends Model
{
    protected $hidden = ['original_projection', 'reviewed_projection'];

    protected $fillable = [
        'document_scan_id', 'record_ref', 'source_pages', 'field_schema_hash', 'field_schema_version',
        'prompt_version', 'contract_version', 'original_projection', 'reviewed_projection', 'revision', 'review_status',
        'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'source_pages' => 'array', 'original_projection' => 'array', 'reviewed_projection' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function documentScan(): BelongsTo
    {
        return $this->belongsTo(DocumentScan::class);
    }

    public function reviewEvents(): HasMany
    {
        return $this->hasMany(ExtractedProfileReviewEvent::class);
    }

    public function merges(): HasMany
    {
        return $this->hasMany(ExtractedProfileMerge::class);
    }
}
