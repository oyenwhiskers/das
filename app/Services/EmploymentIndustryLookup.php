<?php

namespace App\Services;

use App\Services\Bimbsec\Catalog;
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
     * @param  array{employer_name?: string|null, occupation?: string|null, arrangement?: string|null, registration_number?: string|null, city?: string|null, state?: string|null, country?: string|null, industry?: string|null, sub_industry?: string|null}  $employment
     * @param  array{age?: int, date_of_birth?: string, employment_status?: string, employment_status_other?: string, nationality?: string, country_of_residence?: string, residential?: array<string, string>}  $profile
     * @return array{industry: ?string, sub_industry: ?string}
     */
    public function suggest(array $employment, array $profile = []): array
    {
        $employmentContext = $this->nonEmpty($employment, [
            'employer_name', 'occupation', 'arrangement', 'registration_number', 'city', 'state', 'country',
            'industry', 'sub_industry',
        ]);
        $profileContext = $this->profileContext($profile);
        $hasPersonSignal = isset($profileContext['age'])
            || isset($profileContext['residential'])
            || isset($profileContext['employment_status']);

        if ($employmentContext === [] && ! $hasPersonSignal) {
            throw new RuntimeException('Enter employer or occupation, or complete customer age/address before searching industry.');
        }

        $apiKey = config('services.gemini.api_key');
        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('Gemini is not configured.');
        }

        [$maxIndustry, $maxSub] = $this->bimbsecLimits();
        $prompt = 'Recommend industry and sub_industry labels for the BIMBSEC Malaysian investment account form. '
            .'Use Google Search when it helps (especially for a named employer). '
            .'Customer profile (for person context and plausibility; do not invent missing facts): '
            .json_encode($profileContext, JSON_THROW_ON_ERROR).'. '
            .'Employment details from the form (prefer when present): '
            .json_encode($employmentContext, JSON_THROW_ON_ERROR).'. '
            .'BIMBSEC fit: each label must be a short printable phrase of at most '.$maxIndustry.' characters for industry and '.$maxSub.' characters for sub_industry (spaces count). '
            .'Prefer concise Title Case words a clerk would write in character boxes (for example "Manufacturing" / "Aluminium", "Construction" / "General Building", "Professional Services" / "Accounting"). Avoid slogans, company names, punctuation-heavy text, or long descriptions. '
            .'CRITICAL — always return BOTH fields together as non-null strings when you can identify a sector: '
            .'industry is the broad sector; sub_industry MUST be a narrower specialty of that same industry and MUST differ from industry '
            .'(never copy the industry word into sub_industry; never leave sub_industry null or empty when industry is known). '
            .'If the form already has industry or sub_industry, correct obvious typos and complete the missing field so the final pair is coherent. '
            .'Do not copy misspellings from employer_name into labels (for example employer "ROHADI CONTRACTION" → industry "Construction", sub_industry "General Building" or "Civil Works", not "Contraction"). '
            .'Rules: '
            .'1) When employer_name is present, identify that employer\'s industry; supporting employment fields and profile may disambiguate only — do not invent a company that does not match employer_name. '
            .'2) When employer_name is absent or too thin, recommend a logical related pair from occupation and the customer profile. '
            .'Recommendations must be age-appropriate and location-plausible (for example, do not suggest physically demanding sectors such as construction for older customers unless occupation or employer clearly indicates that). Prefer Malaysian market norms. '
            .'3) Return null for BOTH industry and sub_industry only when the answer is unknown, ambiguous, or not supported by the context — never return only one of the two.';

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

        return $this->decode($response, $maxIndustry, $maxSub);
    }

    /** @return array{0: int, 1: int} */
    private function bimbsecLimits(): array
    {
        $catalog = app(Catalog::class);

        return [
            $catalog->cellCapacity('employment.industry', 'Industry') ?? 32,
            $catalog->cellCapacity('employment.sub_industry', 'Sub-industry') ?? 32,
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function profileContext(array $profile): array
    {
        $context = $this->nonEmpty($profile, [
            'date_of_birth', 'employment_status', 'employment_status_other', 'nationality', 'country_of_residence',
        ]);
        if (isset($profile['age']) && is_numeric($profile['age'])) {
            $context['age'] = (int) $profile['age'];
        }
        if (isset($profile['residential']) && is_array($profile['residential'])) {
            $residential = $this->nonEmpty($profile['residential'], ['city', 'state', 'postcode', 'country']);
            if ($residential !== []) {
                $context['residential'] = $residential;
            }
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function nonEmpty(array $source, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $value = trim((string) ($source[$key] ?? ''));
            if ($value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** @return array{industry: ?string, sub_industry: ?string} */
    private function decode(Response $response, int $maxIndustry, int $maxSub): array
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

        $industry = $this->label($result['industry'] ?? null, $maxIndustry);
        $sub = $this->label($result['sub_industry'] ?? null, $maxSub);
        // shortcut: hard relatedness check is limited; AI must keep the pair coherent, upgrade if we add a taxonomy
        if ($industry === null || $sub === null || mb_strtolower($industry) === mb_strtolower($sub)) {
            return ['industry' => null, 'sub_industry' => null];
        }

        return ['industry' => $industry, 'sub_industry' => $sub];
    }

    private function label(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if ($trimmed === '') {
            return null;
        }
        $cut = rtrim(mb_substr($trimmed, 0, $max));

        return $cut === '' ? null : $cut;
    }
}
