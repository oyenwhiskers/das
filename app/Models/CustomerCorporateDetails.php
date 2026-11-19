<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerCorporateDetails extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'customer_id',
        'registered_at',
        'registration_country',
        'entity_type',
        'entity_sub_type',
        'industry',
        'business_activity',
        'authorized_capital_rm',
        'paid_up_capital_rm',
        'aum_bracket',
        'sst',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'registered_at' => 'date',
            'authorized_capital_rm' => 'decimal:2',
            'paid_up_capital_rm' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
