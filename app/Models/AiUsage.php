<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsage extends Model
{
    protected $fillable = ['document_scan_id', 'model', 'http_status', 'input_tokens', 'output_tokens', 'thought_tokens', 'cached_tokens', 'cost_usd'];
}
