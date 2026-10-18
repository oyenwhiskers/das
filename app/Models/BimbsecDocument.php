<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class BimbsecDocument extends Model
{
    protected $fillable = [
        'bimbsec_application_id', 'customer_id', 'source_hash', 'template_sha256', 'mapping_sha256',
        'storage_path', 'page_count', 'warnings', 'print_blocked', 'generated_by', 'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'warnings' => 'array',
            'print_blocked' => 'boolean',
            'generated_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(BimbsecApplication::class, 'bimbsec_application_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function allowsPrint(): bool
    {
        return ! $this->print_blocked;
    }

    protected static function booted(): void
    {
        static::deleting(function (BimbsecDocument $document): void {
            if ($document->storage_path !== '') {
                Storage::disk('local')->delete($document->storage_path);
            }
        });
    }
}
