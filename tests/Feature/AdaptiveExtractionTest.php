<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocumentScan;
use App\Models\AiUsage;
use App\Models\Customer;
use App\Models\DocumentScan;
use App\Models\User;
use App\Services\CustomerProfileSchema;
use App\Services\GeminiDocumentReader;
use App\Services\MergeExtractedProfile;
use App\Services\PdfPageBatches;
use App\Services\SinglePassExtraction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class AdaptiveExtractionTest extends TestCase
{
    use RefreshDatabase;

    public function test_whole_document_extraction_stays_disabled_until_a_page_limit_is_set(): void
    {
        $this->assertSame(0, (int) config('document_scanner.whole_document_max_pages'));
        $this->assertSame(2, (int) config('document_scanner.pages_per_batch'));
    }

    public function test_single_page_document_uses_one_request(): void
    {
        $scan = $this->prepare(1, 2, 16);
        $this->respond([$this->payload([$this->named('Amina Noor', 1)])]);

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $this->assertSame(1, $scan->extractedProfiles()->count());
        $this->assertSame('whole', $scan->fresh()->findings->record_ownership['processing']['mode']);
        $this->assertSame(1, $scan->fresh()->findings->record_ownership['processing']['requests']);
        Http::assertSentCount(1);
    }

    public function test_multi_page_form_stays_one_profile_when_the_whole_document_is_eligible(): void
    {
        $scan = $this->prepare(4, 2, 16);
        $this->respond([$this->payload([[
            'record_ref' => 'r1', 'anchor' => 'Application', 'source_pages' => [1, 3], 'warnings' => [],
            'values' => [
                $this->value('customer.full_legal_name', 'Amina Noor', 'Amina Noor', 1),
                $this->value('addresses[residential].line_1', '12 Jalan Ampang', '12 Jalan Ampang', 3),
            ],
        ]])]);

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $profile = $scan->extractedProfiles()->sole();
        $this->assertEqualsCanonicalizing([1, 3], $profile->source_pages);
        $this->assertEqualsCanonicalizing(
            ['Amina Noor', '12 Jalan Ampang'],
            array_column($profile->original_projection['values'], 'value'),
        );
        $this->assertSame('whole', $scan->fresh()->findings->record_ownership['processing']['mode']);
        Http::assertSentCount(1);
        $this->assertEvidence($scan->fresh());
    }

    public function test_one_request_keeps_applicants_separate(): void
    {
        $scan = $this->prepare(4, 2, 16);
        $this->respond([$this->payload([
            $this->named('Amina Noor', 1, 'r1'),
            $this->named('Siti Amin', 2, 'r2'),
        ])]);

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $profiles = $scan->extractedProfiles()->orderBy('record_ref')->get();
        $this->assertSame(['Amina Noor', 'Siti Amin'], $profiles->map(fn ($profile) => $profile->original_projection['values'][0]['value'])->all());
        $this->assertTrue($profiles->every(fn ($profile): bool => count($profile->original_projection['values']) === 1));
        Http::assertSentCount(1);
    }

    public function test_fourteen_page_application_uses_the_whole_document_path(): void
    {
        $scan = $this->prepare(14, 2, 16);
        $this->respond([$this->payload([[
            'record_ref' => 'r1', 'anchor' => 'Application', 'source_pages' => [1, 14], 'warnings' => [],
            'values' => [
                $this->value('customer.full_legal_name', 'Amina Noor', 'Amina Noor', 1),
                $this->value('addresses[residential].line_1', '12 Jalan Ampang', '12 Jalan Ampang', 14),
            ],
        ]])]);

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $profile = $scan->extractedProfiles()->sole();
        $this->assertEqualsCanonicalizing([1, 14], $profile->source_pages);
        $this->assertSame(14, $scan->fresh()->findings->observations['content_blocks'][1]['evidence']['page_number']);
        $this->assertSame('whole', $scan->fresh()->findings->record_ownership['processing']['mode']);
        Http::assertSentCount(1);
    }

    public function test_thirty_applicants_use_fallback_batches(): void
    {
        $scan = $this->prepare(30, 8, 14);
        $payloads = [];
        $person = 1;
        foreach ([1, 9, 17, 25] as $start) {
            $records = [];
            for ($page = $start; $page <= min(30, $start + 7); $page++) {
                $records[] = $this->named('Person '.$person, $page, 'r'.$person);
                $person++;
            }
            $payloads[] = $this->payload($records);
        }
        $this->respond($payloads);

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $this->assertSame(30, $scan->extractedProfiles()->count());
        $this->assertSame(30, $scan->extractedProfiles()->get()->flatMap(fn ($profile) => array_column($profile->original_projection['values'], 'value'))->unique()->count());
        $this->assertSame('sliced', $scan->fresh()->findings->record_ownership['processing']['mode']);
        Http::assertSentCount(4);
    }

    public function test_a_rejected_whole_document_response_falls_back_once(): void
    {
        $scan = $this->prepare(4, 2, 16);
        $factory = new Factory;
        Http::swap($factory);
        $factory->preventStrayRequests();
        $factory->fake(['generativelanguage.googleapis.com/*' => $factory->sequence()
            ->push(['status' => 'completed', 'output_text' => '{'])
            ->push(['status' => 'completed', 'output_text' => json_encode($this->payload([$this->named('Amina Noor', 1)]))])
            ->push(['status' => 'completed', 'output_text' => json_encode($this->payload([$this->named('Amina Noor', 3, 'r2', 'APP-889912', 'APP-889912')]))]),
        ]);

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $processing = $scan->fresh()->findings->record_ownership['processing'];
        $this->assertSame('sliced', $processing['mode']);
        $this->assertTrue($processing['subdivided']);
        $this->assertSame(1, $processing['validation_failures']);
        $this->assertSame(2, $processing['requests']);
        $this->assertSame(3, AiUsage::query()->where('document_scan_id', $scan->id)->count());
        $factory->assertSentCount(3);
        $this->assertSame(2, $scan->extractedProfiles()->count());
        $this->assertEvidence($scan->fresh());

        $scan->update(['profile_status' => 'not_started']);
        app(SinglePassExtraction::class)->run($scan->fresh(), app(GeminiDocumentReader::class));
        $factory->assertSentCount(3);
        $this->assertSame(2, $scan->fresh()->extractedProfiles()->count());
    }

    public function test_valid_json_does_not_claim_verified_completeness(): void
    {
        $scan = $this->prepare(2, 2, 16);
        $this->respond([$this->payload([$this->named('Amina Noor', 1)])]);
        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $scan->refresh();
        $warnings = array_column($scan->profile_unresolved, 'warning');
        $this->assertContains('Accepted output does not verify that every applicant or field was extracted.', $warnings);
        $this->assertArrayNotHasKey('verified_complete', $scan->findings->observations);
        $this->assertSame('complete', $scan->findings->observations['document']['reading_status']);
    }

    public function test_a_failed_gemini_request_retries_through_the_job_backoff(): void
    {
        $scan = $this->prepare(2, 2, 16);
        $factory = new Factory;
        Http::swap($factory);
        $factory->preventStrayRequests();
        $factory->fake(['generativelanguage.googleapis.com/*' => $factory->response('busy', 503)]);
        $job = new ProcessDocumentScan($scan->id);

        try {
            $job->handle(app(GeminiDocumentReader::class));
            $this->fail('A 503 must leave the scan uncommitted for the queue retry.');
        } catch (RuntimeException $exception) {
            $this->assertSame(503, $exception->getCode());
        }

        $this->assertCount(2, $job->backoff());
        $this->assertSame(0, $scan->extractedProfiles()->count());
        $this->assertNull($scan->fresh()->findings);
        $this->assertSame(1, AiUsage::query()->where('document_scan_id', $scan->id)->count());
    }

    public function test_a_failed_middle_range_keeps_cached_evidence(): void
    {
        $scan = $this->prepare(6, 2, 0);
        $factory = new Factory;
        Http::swap($factory);
        $factory->preventStrayRequests();
        $factory->fake(['generativelanguage.googleapis.com/*' => $factory->sequence()
            ->push(['status' => 'completed', 'output_text' => json_encode($this->payload([$this->named('Amina Noor', 1)]))])
            ->push(['status' => 'completed', 'output_text' => '{'])
            ->push(['status' => 'completed', 'output_text' => json_encode($this->payload([$this->named('Siti Amin', 5)]))])
            ->push(['status' => 'completed', 'output_text' => json_encode($this->payload([$this->named('Farid Ali', 3)]))]),
        ]);

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));
        $kept = $scan->extractedProfiles()->where('record_ref', 'p0001')->sole()->original_projection;
        $block = $scan->fresh()->findings->observations['content_blocks'][0]['id'];

        $scan->update(['profile_status' => 'not_started']);
        app(SinglePassExtraction::class)->run($scan->fresh(), app(GeminiDocumentReader::class));

        $factory->assertSentCount(4);
        $this->assertSame($kept, $scan->extractedProfiles()->where('record_ref', 'p0001')->sole()->original_projection);
        $this->assertSame($block, collect($scan->fresh()->findings->observations['content_blocks'])->firstWhere('id', $block)['id']);
        $this->assertSame(3, $scan->extractedProfiles()->count());
        $this->assertEvidence($scan->fresh());
    }

    public function test_a_later_page_without_a_form_reference_stays_unattached(): void
    {
        $scan = $this->prepare(4, 2, 0);
        $this->respond([
            $this->payload([$this->named('Amina Noor', 1)]),
            $this->payload([$this->named('Amina Noor', 3, 'r9')]),
        ]);

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $this->assertSame(2, $scan->extractedProfiles()->count());
        $this->assertEqualsCanonicalizing(
            ['Amina Noor', 'Amina Noor'],
            $scan->extractedProfiles()->get()->map(fn ($profile) => $profile->original_projection['values'][0]['value'])->all(),
        );
        $this->assertCount(0, collect($scan->fresh()->findings->record_ownership['fragments'])->where('state', 'held'));
    }

    public function test_reviewed_profiles_are_not_reprocessed_when_the_page_limit_changes(): void
    {
        $scan = $this->prepare(4, 2, 0);
        $this->respond([
            $this->payload([$this->named('Amina Noor', 1)]),
            $this->payload([$this->named('Siti Amin', 3, 'r2')]),
        ]);
        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));
        $profile = $scan->extractedProfiles()->where('record_ref', 'p0001')->sole();
        $profile->update(['revision' => 1, 'review_status' => 'reviewed']);
        $reviewed = $profile->fresh()->reviewed_projection;
        config()->set('document_scanner.whole_document_max_pages', 30);
        $scan->update(['profile_status' => 'not_started']);

        app(SinglePassExtraction::class)->run($scan->fresh(), app(GeminiDocumentReader::class));

        Http::assertSentCount(2);
        $this->assertSame($reviewed, $profile->fresh()->reviewed_projection);
        $this->assertSame(2, $scan->fresh()->extractedProfiles()->count());
    }

    public function test_a_new_profile_without_an_ownership_key_cannot_merge(): void
    {
        CustomerProfileSchema::snapshot();
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $scan = DocumentScan::factory()->for($staff, 'uploader')->create();
        $projection = ['record_ref' => 'p0001', 'values' => [], 'warnings' => []];
        $profile = $scan->extractedProfiles()->create([
            'record_ref' => 'p0001', 'source_pages' => [1], 'field_schema_hash' => CustomerProfileSchema::hash(),
            'field_schema_version' => CustomerProfileSchema::VERSION, 'prompt_version' => SinglePassExtraction::PROMPT_VERSION,
            'contract_version' => '1.0', 'original_projection' => $projection, 'reviewed_projection' => $projection,
            'review_status' => 'reviewed',
        ]);

        try {
            app(MergeExtractedProfile::class)->commit($profile, $customer, $staff, 'fill_empty', 'new-key', 'baseline', [], []);
            $this->fail('A new profile without ownership must not merge.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('ownership', $exception->getMessage());
        }

        $legacy = $scan->extractedProfiles()->create([
            'record_ref' => 'r1', 'source_pages' => [1], 'field_schema_hash' => CustomerProfileSchema::hash(),
            'field_schema_version' => CustomerProfileSchema::VERSION, 'prompt_version' => '1',
            'contract_version' => '1.0', 'original_projection' => $projection, 'reviewed_projection' => $projection,
            'review_status' => 'reviewed',
        ]);
        try {
            app(MergeExtractedProfile::class)->commit($legacy, $customer, $staff, 'fill_empty', 'legacy-key', 'baseline', [], []);
        } catch (ValidationException $exception) {
            $this->assertStringNotContainsString('Resolve applicant ownership', $exception->getMessage());
        }
    }

    public function test_historical_findings_remain_unchanged(): void
    {
        $scan = $this->prepare(2, 2, 16);
        $observations = ['fields' => [['id' => 'legacy']], 'sections' => [], 'tables' => []];
        $scan->findings()->create([
            'schema_version' => '1.0', 'observations' => $observations, 'record_ownership' => ['records' => [], 'findings' => []],
        ]);
        Http::preventStrayRequests();
        Http::fake();

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        Http::assertSentCount(0);
        $this->assertSame($observations, $scan->fresh()->findings->observations);
        $this->assertSame(0, $scan->extractedProfiles()->count());
    }

    private function prepare(int $pages, int $size, int $maxPages): DocumentScan
    {
        Storage::fake('local');
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite');
        config()->set('document_scanner.pages_per_batch', $size);
        config()->set('document_scanner.whole_document_max_pages', $maxPages);
        $scan = DocumentScan::factory()->for(User::factory()->staff(), 'uploader')->create([
            'profile_schema_hash' => CustomerProfileSchema::hash(),
        ]);
        CustomerProfileSchema::snapshot();
        Storage::disk('local')->put($scan->storage_path, '%PDF-1.4 sample');
        app()->instance(PdfPageBatches::class, new class($pages) extends PdfPageBatches
        {
            public function __construct(private int $pages) {}

            public function make(DocumentScan $scan, int $size): array
            {
                $batches = [];
                for ($start = 1; $start <= $this->pages; $start += $size) {
                    $batches[$start] = '%PDF';
                }

                return ['count' => $this->pages, 'batches' => $batches];
            }
        });

        return $scan;
    }

    /** @param list<array<string, mixed>> $payloads */
    private function respond(array $payloads): void
    {
        $factory = new Factory;
        Http::swap($factory);
        $sequence = $factory->sequence();
        foreach ($payloads as $payload) {
            $sequence->push(['status' => 'completed', 'output_text' => json_encode($payload)]);
        }
        $factory->preventStrayRequests();
        $factory->fake(['generativelanguage.googleapis.com/*' => $sequence]);
    }

    /** @param list<array<string, mixed>> $records @return array<string, mixed> */
    private function payload(array $records): array
    {
        return [
            'contract_version' => '2.0', 'schema_hash' => CustomerProfileSchema::hash(),
            'document_type' => 'Application', 'title' => 'Form', 'summary' => 'Applications.',
            'readable' => true, 'limitations' => null, 'records' => $records, 'unresolved' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function named(string $name, int $page, string $ref = 'r1', ?string $form = null, ?string $formEvidence = null): array
    {
        return [
            'record_ref' => $ref, 'anchor' => 'Application', 'source_pages' => [$page], 'warnings' => [],
            'form_reference' => $form, 'form_reference_evidence' => $formEvidence,
            'values' => [$this->value('customer.full_legal_name', $name, $name.($form ? ' '.$form : ''), $page)],
        ];
    }

    /** @return array<string, mixed> */
    private function value(string $field, string $value, string $quote, int $page): array
    {
        return [
            'field_id' => $field, 'entity_ref' => 'person-1', 'entity_role' => 'Applicant', 'row_ref' => null,
            'state' => 'provided', 'value' => $value, 'source_page' => $page, 'evidence_text' => $quote,
        ];
    }

    private function assertEvidence(DocumentScan $scan): void
    {
        $ids = collect($scan->findings->observations['content_blocks'])->pluck('id');
        foreach ($scan->extractedProfiles as $profile) {
            foreach ($profile->original_projection['values'] as $value) {
                $this->assertNotEmpty($value['evidence_ids']);
                foreach ($value['evidence_ids'] as $id) {
                    $this->assertTrue($ids->contains($id));
                }
            }
        }
    }
}
