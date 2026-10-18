<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerReferenceCounter extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'last_value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_value' => 'integer',
        ];
    }
}
