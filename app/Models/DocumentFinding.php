<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentFinding extends Model
{
    /** @var list<string> */
    protected $fillable = ['document_scan_id', 'schema_version', 'observations', 'record_ownership'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['observations' => 'array', 'record_ownership' => 'array'];
    }

    public function documentScan(): BelongsTo
    {
        return $this->belongsTo(DocumentScan::class);
    }
}
