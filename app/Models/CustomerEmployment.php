<?php

namespace App\Models;

use Database\Factories\CustomerEmploymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerEmployment extends Model
{
    /** @use HasFactory<CustomerEmploymentFactory> */
    use HasFactory;

    protected $fillable = [
        'arrangement', 'occupation', 'employer_name', 'registration_number', 'industry', 'sub_industry',
        'start_date', 'end_date', 'is_current', 'is_primary', 'primary_current_key',
        'general_telephone', 'direct_telephone', 'fax', 'address_line_1', 'address_line_2',
        'city', 'state', 'postcode', 'country',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_current' => 'boolean',
            'is_primary' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
