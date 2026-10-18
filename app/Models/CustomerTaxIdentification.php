<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerTaxIdentification extends Model
{
    protected $fillable = ['malaysian_tin'];

    protected $hidden = ['malaysian_tin'];

    protected function casts(): array
    {
        return ['malaysian_tin' => 'encrypted'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function maskedTin(): string
    {
        $tin = $this->malaysian_tin;

        if (! $tin) {
            return 'Not Provided';
        }

        return mb_strlen($tin) <= 4
            ? str_repeat('*', mb_strlen($tin))
            : str_repeat('*', max(4, mb_strlen($tin) - 4)).mb_substr($tin, -4);
    }
}
