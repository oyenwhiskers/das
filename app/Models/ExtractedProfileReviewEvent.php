<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExtractedProfileReviewEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['actor_id', 'revision', 'previous_value', 'new_value', 'reason', 'created_at'];

    protected function casts(): array
    {
        return ['previous_value' => 'array', 'new_value' => 'array', 'created_at' => 'datetime'];
    }
}
