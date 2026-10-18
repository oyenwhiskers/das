<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerInvestmentProfile extends Model
{
    protected $fillable = ['objectives', 'risk_tolerance', 'trading_knowledge', 'products', 'other_product_details', 'information_source', 'declared_at', 'recorded_by'];

    protected function casts(): array
    {
        return ['objectives' => 'array', 'products' => 'array', 'declared_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
