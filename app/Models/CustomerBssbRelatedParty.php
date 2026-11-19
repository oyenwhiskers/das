<?php

namespace App\Models;

use App\Enums\BssbRelatedPartyKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerBssbRelatedParty extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'customer_id',
        'kind',
        'name',
        'relationship',
        'identification_number',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => BssbRelatedPartyKind::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
