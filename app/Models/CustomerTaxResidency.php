<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerTaxResidency extends Model
{
    protected $fillable = [
        'jurisdiction', 'jurisdiction_key', 'current_jurisdiction_key', 'foreign_tin',
        'missing_tin_reason', 'missing_tin_explanation', 'effective_date', 'ended_on',
    ];

    protected $hidden = ['foreign_tin'];

    protected function casts(): array
    {
        return [
            'foreign_tin' => 'encrypted',
            'effective_date' => 'date',
            'ended_on' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function maskedTin(): string
    {
        $tin = $this->foreign_tin;

        if (! $tin) {
            return 'Not Provided';
        }

        return mb_strlen($tin) <= 4
            ? str_repeat('*', mb_strlen($tin))
            : str_repeat('*', max(4, mb_strlen($tin) - 4)).mb_substr($tin, -4);
    }
}
