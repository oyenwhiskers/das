<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtractedProfileMerge extends Model
{
    public const UPDATED_AT = null;

    protected $hidden = ['changes'];

    protected $fillable = [
        'submission_key', 'extracted_profile_id', 'customer_id', 'reviewer_revision',
        'strategy', 'changes', 'merged_by', 'created_at',
    ];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    public function extractedProfile(): BelongsTo
    {
        return $this->belongsTo(ExtractedProfile::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function merger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by');
    }

    public function strategyLabel(): string
    {
        return match ($this->strategy) {
            'fill_empty' => 'Fill empty only',
            'overwrite' => 'Overwrite and fill empty',
            'replace_all' => 'Replace all',
            default => $this->strategy,
        };
    }
}
