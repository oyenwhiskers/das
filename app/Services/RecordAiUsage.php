<?php

namespace App\Services;

use App\Models\AiUsage;
use App\Models\DocumentScan;
use Illuminate\Http\Client\Response;

class RecordAiUsage
{
    public function record(?DocumentScan $scan, ?Response $response): void
    {
        $usage = $response?->json('usage');
        $input = $this->tokens($usage, 'total_input_tokens');
        $output = $this->tokens($usage, 'total_output_tokens');
        $thought = $this->tokens($usage, 'total_thought_tokens');
        $cached = $this->tokens($usage, 'total_cached_tokens');
        $model = config('services.gemini.model');
        $cost = null;

        if ($model === 'gemini-3.1-flash-lite' && $input !== null && $output !== null && $thought !== null && $cached !== null && $cached <= $input) {
            $cost = round((($input - $cached) * 0.25 + $cached * 0.025
                + ($output + $thought) * 1.50) / 1_000_000, 6);
        }

        AiUsage::query()->create([
            'document_scan_id' => $scan?->id,
            'model' => $model,
            'http_status' => $response?->status(),
            'input_tokens' => $input,
            'output_tokens' => $output,
            'thought_tokens' => $this->tokens($usage, 'total_thought_tokens'),
            'cached_tokens' => $this->tokens($usage, 'total_cached_tokens'),
            'cost_usd' => $cost,
        ]);
    }

    private function tokens(mixed $usage, string $key): ?int
    {
        $value = is_array($usage) ? ($usage[$key] ?? null) : null;

        return is_int($value) && $value >= 0 ? $value : null;
    }
}
