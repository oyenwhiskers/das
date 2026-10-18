<?php

namespace App\Models;

use App\Enums\AddressType;
use App\Enums\OccupancyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAddress extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'type',
        'line_1',
        'line_2',
        'city',
        'state',
        'postcode',
        'country',
        'occupancy_status',
        'occupancy_other',
        'same_as_residential',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AddressType::class,
            'occupancy_status' => OccupancyStatus::class,
            'same_as_residential' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isEmpty(): bool
    {
        return blank($this->line_1)
            && blank($this->line_2)
            && blank($this->city)
            && blank($this->state)
            && blank($this->postcode)
            && blank($this->country)
            && blank($this->occupancy_status);
    }
}
