<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerFatcaDeclaration extends Model
{
    protected $fillable = [
        'current_customer_key', 'us_person_for_tax', 'us_green_card_holder',
        'us_resident', 'selected_classification', 'declared_on', 'source', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'us_person_for_tax' => 'boolean',
            'us_green_card_holder' => 'boolean',
            'us_resident' => 'boolean',
            'declared_on' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function classification(): string
    {
        $answers = [$this->us_person_for_tax, $this->us_green_card_holder, $this->us_resident];

        if (in_array(true, $answers, true)) {
            return 'US Person';
        }

        return count(array_filter($answers, fn (?bool $answer): bool => $answer === false)) === 3
            ? 'Non-US Person' : 'Undetermined';
    }

    public function isComplete(): bool
    {
        return $this->us_person_for_tax !== null
            && $this->us_green_card_holder !== null
            && $this->us_resident !== null;
    }
}
