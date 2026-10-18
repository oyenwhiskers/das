<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerComplianceDeclaration extends Model
{
    /** @var list<string> */
    protected $fillable = ['customer_id', 'kind', 'answer', 'remarks', 'declared_on', 'source', 'confirmation_status', 'recorded_by', 'current_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['declared_on' => 'date'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
