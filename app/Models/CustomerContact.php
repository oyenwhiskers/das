<?php

namespace App\Models;

use App\Enums\ContactType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerContact extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'type',
        'value',
        'normalized_value',
        'is_primary',
        'platform',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ContactType::class,
            'is_primary' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public static function normalizeValue(ContactType|string $type, ?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $type = $type instanceof ContactType ? $type : ContactType::from($type);

        return match ($type) {
            ContactType::Email => strtolower(trim($value)),
            ContactType::Mobile, ContactType::Home => preg_replace('/[^\d+]/', '', $value) ?? '',
            ContactType::Social => trim($value),
        };
    }
}
