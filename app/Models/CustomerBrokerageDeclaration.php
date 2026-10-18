<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerBrokerageDeclaration extends Model
{
    protected $fillable = ['current_customer_key', 'answer', 'declared_on', 'source', 'recorded_by'];

    protected function casts(): array
    {
        return ['declared_on' => 'date'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
