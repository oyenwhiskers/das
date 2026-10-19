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

    public static function formatNumber(?string $number, IdentificationType|string|null $type = null): ?string
    {
        if (blank($number)) {
            return null;
        }

        $trimmed = trim($number);
        $isNric = $type === IdentificationType::Nric
            || $type === IdentificationType::Nric->value
            || (is_string($type) && strtolower($type) === 'nric');

        if (! $isNric) {
            return $trimmed;
        }

        $normalized = self::normalizeNumber($trimmed);
        if ($normalized !== null && preg_match('/^\d{12}$/', $normalized) === 1) {
            return substr($normalized, 0, 6).'-'.substr($normalized, 6, 2).'-'.substr($normalized, 8, 4);
        }

        return $trimmed;
    }
}
