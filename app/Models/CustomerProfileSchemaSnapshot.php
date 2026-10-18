<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerProfileSchemaSnapshot extends Model
{
    protected $fillable = ['hash', 'version', 'canonical_json'];

    public function schema(): array
    {
        return json_decode($this->canonical_json, true, flags: JSON_THROW_ON_ERROR);
    }
}
