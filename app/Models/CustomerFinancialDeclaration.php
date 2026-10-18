<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerFinancialDeclaration extends Model
{
    protected $fillable = [
        'current_customer_key', 'monthly_income_rm', 'personal_annual_income_bracket',
        'joint_annual_income_bracket', 'net_asset_bracket', 'portfolio_bracket',
        'portfolio_ownership', 'wealth_sources', 'other_wealth_source', 'declared_on',
        'source', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['monthly_income_rm' => 'decimal:2', 'wealth_sources' => 'array', 'declared_on' => 'date'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
