<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;
use UnexpectedValueException;

class EmploymentIndustryLookup
{
    /**
     * @param  array{employer_name: string, occupation?: string|null, arrangement?: string|null, registration_number?: string|null, city?: string|null, state?: string|null, country?: string|null}  $employment
     * @return array{industry: ?string, sub_industry: ?string}
     */
    public function suggest(array $employment): array
    {
        $employer = trim((string) ($employment['employer_name'] ?? ''));
        if ($employer === '') {
            throw new RuntimeException('Employer name is required to search industry.');
        }

        $apiKey = config('services.gemini.api_key');
        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('Gemini is not configured.');
        }

        $support = [];
        foreach (['occupation', 'arrangement', 'registration_number', 'city', 'state', 'country'] as $key) {
            $value = trim((string) ($employment[$key] ?? ''));
            if ($value !== '') {
                $support[$key] = $value;
            }
        }

        $prompt = 'Identify the employer company from the employer_name and return its industry and sub-industry as short free-text labels suitable for a Malaysian investment account form. '
            .'Use Google Search to research the company when needed. Supporting employment fields may disambiguate only; do not invent a company that does not match the employer_name. '
            .'Return null for both industry and sub_industry when the company is unknown, ambiguous, or the industry is unclear. '
            .'employer_name: '.$employer.'. '
            .'supporting: '.json_encode($support, JSON_THROW_ON_ERROR).'.';

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->connectTimeout(10)
                ->timeout(90)
                ->post('https://generativelanguage.googleapis.com/v1beta/interactions', [
                    'model' => config('services.gemini.model'),
                    'store' => false,
                    'input' => $prompt,
                    'tools' => [['type' => 'google_search']],
                    'response_format' => [
                        'type' => 'text',
                        'mime_type' => 'application/json',
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'industry' => ['type' => ['string', 'null']],
                                'sub_industry' => ['type' => ['string', 'null']],
                            ],
                            'required' => ['industry', 'sub_industry'],
                        ],
                    ],
                ]);
        } catch (ConnectionException $exception) {
            app(RecordAiUsage::class)->record(null, null);
            throw new RuntimeException('Gemini could not look up the employer industry.', previous: $exception);
        }

        app(RecordAiUsage::class)->record(null, $response);

        if (! $response->successful()) {
            Log::warning('Gemini employment industry lookup failed.', [
                'http_status' => $response->status(),
                'api_error_status' => $response->json('error.status') ?? $response->json('error.code'),
            ]);
            throw new RuntimeException('Gemini could not look up the employer industry.');
        }

        return $this->decode($response);
    }

    /** @return array{industry: ?string, sub_industry: ?string} */
    private function decode(Response $response): array
    {
        $payload = $response->json();
        if (! is_array($payload) || ($payload['status'] ?? null) !== 'completed') {
            throw new UnexpectedValueException('Gemini returned an incomplete industry lookup.');
        }

        $text = $payload['output_text'] ?? null;
        if (! is_string($text) && is_array($payload['steps'] ?? null)) {
            foreach (array_reverse($payload['steps']) as $step) {
                if (is_array($step) && ($step['type'] ?? null) === 'model_output') {
                    $text = $step['content'][0]['text'] ?? null;
                    break;
                }
            }
        }

        if (! is_string($text)) {
            throw new UnexpectedValueException('Gemini returned no industry lookup result.');
        }

        try {
            $result = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('Gemini returned invalid industry lookup data.', previous: $exception);
        }

        if (! is_array($result)) {
            throw new UnexpectedValueException('Gemini returned invalid industry lookup data.');
        }

        $industry = $this->label($result['industry'] ?? null);
        $sub = $this->label($result['sub_industry'] ?? null);

        return ['industry' => $industry, 'sub_industry' => $sub];
    }

    private function label(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 255);
    }
}
