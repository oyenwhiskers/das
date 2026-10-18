<?php

namespace App\Models;

use App\Enums\IdentificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerIdentification extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'type',
        'number',
        'normalized_number',
        'issuing_jurisdiction',
        'old_nric',
        'is_primary',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => IdentificationType::class,
            'is_primary' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public static function normalizeNumber(?string $number): ?string
    {
        if (blank($number)) {
            return null;
        }

        $normalized = strtoupper(preg_replace('/[\s\-]/u', '', trim($number)) ?? '');

        return $normalized === '' ? null : $normalized;
    }
}
