<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class BimbsecBatch extends Model
{
    protected $fillable = [
        'user_id', 'status', 'application_ids', 'summary', 'storage_path', 'complete',
    ];

    protected function casts(): array
    {
        return [
            'application_ids' => 'array',
            'summary' => 'array',
            'complete' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::deleting(function (BimbsecBatch $batch): void {
            if (is_string($batch->storage_path) && $batch->storage_path !== '') {
                Storage::disk('local')->delete($batch->storage_path);
            }
        });
    }
}
