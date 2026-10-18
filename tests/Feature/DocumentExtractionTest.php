<?php

namespace Tests\Feature;

use App\Enums\DocumentScanStatus;
use App\Jobs\FinalizeDocumentExtraction;
use App\Jobs\ProcessDocumentBatch;
use App\Jobs\ProcessDocumentExtraction;
use App\Jobs\ProcessDocumentScan;
use App\Livewire\Documents\Show;
use App\Models\Customer;
use App\Models\DocumentScan;
use App\Models\User;
use App\Services\CustomerProfileSchema;
use App\Services\GeminiDocumentReader;
use App\Services\StoreDocumentFindings;
use FPDF;
use Illuminate\Bus\PendingBatch;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\DocumentFindingsFixtures;
use Tests\TestCase;

class DocumentExtractionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function documentTypes(): array
    {
        return [
            'identity' => ['identity'], 'payslip' => ['payslip'],
            'bank statement' => ['bankStatement'], 'application' => ['application'],
            'conflicting form' => ['conflictingForm'], 'contract' => ['contract'],
        ];
    }

    public function test_fourteen_pages_are_queued_as_seven_independent_worker_jobs(): void
    {
        $scan = $this->readableScan();
        $this->putPdf($scan, 14);
        $customer = Customer::factory()->create();
        $customerBefore = $customer->fresh()->getAttributes();
        config()->set('document_scanner.pages_per_batch', 2);
        Bus::fake();
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($scan) {
            preg_match('/original pages (\d+) through (\d+)/', $request['input'][1]['text'], $matches);
            $this->assertTrue(Storage::disk('local')->exists('document-scans/'.$scan->id.'/extraction-batches/'.$matches[1].'.running'));

            return Http::response([
                'status' => 'completed',
                'output_text' => json_encode($this->batchPayload($scan, (int) $matches[1]), JSON_THROW_ON_ERROR),
            ]);
        });

        $this->process($scan);

        $jobs = [];
        Bus::assertBatched(function (PendingBatch $batch) use (&$jobs): bool {
            $jobs = collect($batch->jobs)->all();

            return $batch->name === 'document-extraction-'.$this->scanId($jobs)
                && count($jobs) === 7;
        });
        $this->assertSame(range(1, 13, 2), array_map(fn (ProcessDocumentBatch $job): int => $job->startPage, $jobs));
        $this->assertSame(array_fill(0, 7, 3), array_map(fn (ProcessDocumentBatch $job): int => $job->tries, $jobs));
        $this->assertSame('processing', $scan->fresh()->extraction_status);
        $this->actingAs(User::query()->findOrFail($scan->uploaded_by))
            ->get(route('documents.show', $scan))
            ->assertSeeInOrder(['Group 1', 'Waiting', 'Group 2', 'Waiting']);
        Storage::disk('local')->put('document-scans/'.$scan->id.'/extraction-batches/1.running', (string) time());
        $this->get(route('documents.show', $scan))
            ->assertSeeInOrder(['Group 1', 'Extracting', 'Group 2', 'Waiting']);
        Storage::disk('local')->delete('document-scans/'.$scan->id.'/extraction-batches/1.running');
        foreach ($jobs as $index => $job) {
            $this->assertTrue(Storage::disk('local')->exists('document-scans/'.$scan->id.'/extraction-batches/'.$job->startPage.'.pdf'));
            $job->handle(app(GeminiDocumentReader::class));
            $this->assertFalse(Storage::disk('local')->exists('document-scans/'.$scan->id.'/extraction-batches/'.$job->startPage.'.running'));
            if ($index === 0) {
                $this->get(route('documents.show', $scan))
                    ->assertSeeInOrder(['Group 1', 'Complete', 'Group 2', 'Waiting']);
            }
        }
        $this->get(route('documents.show', $scan))
            ->assertSee('Combining extracted pages')
            ->assertSeeInOrder(['Group 7', 'Complete']);
        (new FinalizeDocumentExtraction($scan->id, 14, array_map(
            fn (ProcessDocumentBatch $job): array => ['start' => $job->startPage, 'end' => $job->endPage],
            $jobs
        )))->handle(app(GeminiDocumentReader::class), app(StoreDocumentFindings::class));

        $stored = $scan->fresh()->findings->observations;
        $this->assertSame('completed', $scan->fresh()->extraction_status);
        $this->get(route('documents.show', $scan))
            ->assertSeeInOrder(['Group 1', 'Complete', 'Group 7', 'Complete']);
        $this->assertSame(range(1, 14), array_column($stored['pages'], 'number'));
        $this->assertSame(range(1, 13, 2), array_map(fn (array $field): int => $field['evidence']['page_number'], $stored['fields']));
        $this->assertCount(7, $stored['fields']);
        $this->assertCount(0, $stored['tables']);
        $this->assertCount(0, $stored['content_blocks']);
        $this->assertCount(0, $stored['sections']);
        $this->assertCount(7, $stored['entities']);
        $this->assertSame($customerBefore, $customer->fresh()->getAttributes());
        Http::assertSentCount(7);
    }

    public function test_failed_page_group_remains_visible_beside_waiting_groups(): void
    {
        $scan = $this->readableScan();
        $this->putPdf($scan, 4);
        Bus::fake();
        $this->process($scan);
        $job = $this->batchedJobs()[0];
        $job->failed(new RuntimeException('Gemini could not process the document.', 503));

        $this->actingAs(User::query()->findOrFail($scan->uploaded_by))
            ->get(route('documents.show', $scan))
            ->assertSeeInOrder(['Group 1', 'Failed', 'Group 2', 'Waiting']);
    }

    public function test_detail_page_explains_waiting_before_page_groups_are_ready(): void
    {
        $scan = $this->readableScan();
        $scan->update(['extraction_status' => 'not_started']);

        $this->actingAs(User::query()->findOrFail($scan->uploaded_by))
            ->get(route('documents.show', $scan))
            ->assertSee('Waiting for the extraction worker.');

        $scan->update(['extraction_status' => 'processing']);
        $this->get(route('documents.show', $scan))
            ->assertSee('Preparing PDF pages or reading the document')
            ->assertSee('A page count is not available yet.');
    }

    public function test_documents_list_shows_extraction_state_after_basic_reading(): void
    {
        $scan = $this->readableScan();
        $scan->update(['extraction_status' => 'processing']);

        $this->actingAs(User::query()->findOrFail($scan->uploaded_by))
            ->get(route('documents.index'))
            ->assertSee('Extracting');

        $scan->update(['extraction_status' => 'failed']);
        $this->get(route('documents.index'))->assertSee('Extraction failed');
    }

    /** @return array<string, array{int}> */
    public static function transientStatuses(): array
    {
        return ['rate limited' => [429], 'unavailable' => [503]];
    }

    #[DataProvider('transientStatuses')]
    public function test_failed_batch_retries_without_repeating_successful_batches(int $status): void
    {
        $scan = $this->readableScan();
        $this->putPdf($scan, 14);
        Bus::fake();
        $calls = [];
        $fail = true;
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($scan, &$calls, &$fail, $status) {
            preg_match('/original pages (\d+) through (\d+)/', $request['input'][1]['text'], $matches);
            $start = (int) $matches[1];
            $calls[$start] = ($calls[$start] ?? 0) + 1;
            if ($start === 3 && $fail) {
                return Http::response(['error' => ['status' => 'UNAVAILABLE']], $status);
            }

            return Http::response(['status' => 'completed', 'output_text' => json_encode($this->batchPayload($scan, $start), JSON_THROW_ON_ERROR)]);
        });

        $this->process($scan);
        $jobs = $this->batchedJobs();
        foreach ($jobs as $job) {
            try {
                $job->handle(app(GeminiDocumentReader::class));
            } catch (RuntimeException $exception) {
                $this->assertSame(3, $job->startPage);
            }
        }
        $this->assertSame(1, $calls[3]);
        $this->assertSame(1, $calls[1]);
        $this->assertTrue(Storage::disk('local')->exists('document-scans/'.$scan->id.'/extraction-batches/1.json'));

        $fail = false;
        collect($jobs)->first(fn (ProcessDocumentBatch $job): bool => $job->startPage === 3)
            ->handle(app(GeminiDocumentReader::class));
        $this->finalizeJobs($scan, $jobs);
        $this->assertSame('completed', $scan->fresh()->extraction_status);
        $this->assertDatabaseCount('document_findings', 1);
        $this->assertSame(2, $calls[3]);
        $this->assertSame(1, $calls[1]);
        $this->assertCount(7, $scan->fresh()->findings->observations['fields']);
    }

    public function test_batch_timeout_retries_only_the_timed_out_batch(): void
    {
        $scan = $this->readableScan();
        $this->putPdf($scan, 14);
        Bus::fake();
        $calls = [];
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($scan, &$calls) {
            preg_match('/original pages (\d+) through (\d+)/', $request['input'][1]['text'], $matches);
            $start = (int) $matches[1];
            $calls[$start] = ($calls[$start] ?? 0) + 1;
            if ($start === 3 && $calls[$start] === 1) {
                return Http::failedConnection('Simulated timeout')($request);
            }

            return Http::response(['status' => 'completed', 'output_text' => json_encode($this->batchPayload($scan, $start), JSON_THROW_ON_ERROR)]);
        });

        $this->process($scan);
        $jobs = $this->batchedJobs();
        foreach ($jobs as $job) {
            try {
                $job->handle(app(GeminiDocumentReader::class));
            } catch (\Throwable $exception) {
                $this->assertSame(3, $job->startPage);
                $job->handle(app(GeminiDocumentReader::class));
            }
        }
        $this->finalizeJobs($scan, $jobs);

        $this->assertSame('completed', $scan->fresh()->extraction_status);
        $this->assertSame(2, $calls[3]);
        $this->assertSame(1, $calls[1]);
    }

    public function test_batch_uses_inline_pdf_and_does_not_retry_http_404(): void
    {
        $scan = $this->readableScan();
        $this->putPdf($scan, 2);
        $reader = app(GeminiDocumentReader::class);
        $reader->preparePdfBatches($scan);
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'error' => ['code' => 'not_found', 'message' => 'Requested resource "private-token-12345678901234567890" was not found.'],
        ], 404)]);
        Log::spy();
        $queued = \Mockery::mock(Job::class);
        $queued->shouldReceive('attempts')->once()->andReturn(1);
        $queued->shouldReceive('fail')->once()->with(\Mockery::type(RuntimeException::class));
        $job = new ProcessDocumentBatch($scan->id, 2, 1, 2);
        $job->setJob($queued);

        $job->handle($reader);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/interactions'
            && $request['model'] === config('services.gemini.model')
            && $request['input'][0]['type'] === 'document'
            && $request['input'][0]['mime_type'] === 'application/pdf'
            && base64_decode($request['input'][0]['data'], true) === Storage::disk('local')->get('document-scans/'.$scan->id.'/extraction-batches/1.pdf'));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Gemini document processing request failed.'
            && $context['http_status'] === 404 && $context['api_error_status'] === 'not_found'
            && ! str_contains($context['api_error_message'], 'private-token'))->once();
    }

    public function test_batch_validation_logs_field_type_without_field_value_and_stops_retry(): void
    {
        $scan = $this->readableScan();
        $this->putPdf($scan, 14);
        $reader = app(GeminiDocumentReader::class);
        $reader->preparePdfBatches($scan);
        $payload = $this->batchPayload($scan, 5);
        $payload['fields'][0]['type'] = 'number';
        $payload['fields'][0]['normalized_value'] = ['invalid'];
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => function () use (&$payload) {
            return Http::response(['status' => 'completed', 'output_text' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        }]);
        Log::spy();
        $queued = \Mockery::mock(Job::class);
        $queued->shouldReceive('attempts')->once()->andReturn(1);
        $queued->shouldReceive('fail')->once()->with(\Mockery::type(ValidationException::class));
        $job = new ProcessDocumentBatch($scan->id, 14, 5, 6);
        $job->setJob($queued);

        $job->handle($reader);

        Http::assertSentCount(1);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Document page batch failed findings validation.'
            && $context['normalized_value_types']['fields.0.normalized_value']['expected'] === 'decimal string'
            && $context['normalized_value_types']['fields.0.normalized_value']['actual_json_type'] === 'array'
            && ! str_contains(json_encode($context), 'invalid'))->once();
    }

    public function test_batch_accepts_complete_pages_with_partial_document_status_and_numeric_field(): void
    {
        $scan = $this->readableScan();
        $this->putPdf($scan, 14);
        $reader = app(GeminiDocumentReader::class);
        $reader->preparePdfBatches($scan);
        $payload = $this->batchPayload($scan, 1);
        $payload['document']['reading_status'] = 'partial';
        $payload['fields'][0]['type'] = 'number';
        $payload['fields'][0]['normalized_value'] = 4200.5;
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed', 'output_text' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])]);

        $reader->extractPdfBatch($scan, 14, 1, 2);

        $result = json_decode(Storage::disk('local')->get('document-scans/'.$scan->id.'/extraction-batches/1.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('complete', $result['document']['reading_status']);
        $this->assertSame('4200.5', $result['fields'][0]['normalized_value']);
    }

    public function test_empty_page_is_valid_but_missing_page_is_reported(): void
    {
        $scan = $this->readableScan();
        $this->putPdf($scan, 14);
        $reader = app(GeminiDocumentReader::class);
        $reader->preparePdfBatches($scan);
        $payload = $this->batchPayload($scan, 7);
        $payload['fields'] = [];
        $payload['entities'] = [];
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => function () use (&$payload) {
            return Http::response(['status' => 'completed', 'output_text' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        }]);
        $reader->extractPdfBatch($scan, 14, 7, 8);
        $this->assertTrue($reader->hasValidPdfBatch($scan, 14, 7, 8));

        Storage::disk('local')->delete('document-scans/'.$scan->id.'/extraction-batches/7.json');
        $payload['pages'] = array_slice($payload['pages'], 0, 1);
        try {
            $reader->extractPdfBatch($scan, 14, 7, 8);
            $this->fail('Missing page 8 should fail coverage.');
        } catch (\UnexpectedValueException $exception) {
            $this->assertStringContainsString('Expected pages 7, 8; declared page count 14; received', $exception->getMessage());
            $this->assertStringContainsString('"number":7', $exception->getMessage());
        }
    }

    public function test_batch_derives_choice_value_from_marked_option_ids(): void
    {
        $scan = $this->readableScan();
        $this->putPdf($scan, 2);
        config()->set('document_scanner.pages_per_batch', 1);
        Bus::fake();
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($scan) {
            $prompt = $request['input'][1]['text'];
            if (str_contains($prompt, 'Visually reread')) {
                return Http::response(['status' => 'completed', 'output_text' => json_encode([
                    'coverage_gaps' => [],
                    'groups' => [[
                        'id' => 'marital',
                        'options' => [
                            ['id' => 'single', 'mark_state' => 'unmarked'],
                            ['id' => 'married', 'mark_state' => 'marked'],
                            ['id' => 'divorced', 'mark_state' => 'unmarked'],
                        ],
                    ]],
                ], JSON_THROW_ON_ERROR)]);
            }

            preg_match('/original pages (\d+) through (\d+)/', $prompt, $matches);
            $start = (int) $matches[1];
            $payload = DocumentFindingsFixtures::identity($scan->id);
            $payload['document']['page_count'] = 2;
            $payload['pages'] = [['number' => $start, 'reading_status' => 'complete', 'section_ids' => [], 'issues' => []]];
            $payload['entities'] = [];
            $payload['fields'] = [];
            if ($start === 1) {
                $choice = DocumentFindingsFixtures::application($scan->id)['fields'][3];
                $choice['normalized_value'] = ['Married'];
                $payload['fields'][] = $choice;
            }

            return Http::response([
                'status' => 'completed',
                'output_text' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);
        });

        $this->process($scan);
        $jobs = $this->batchedJobs();
        foreach ($jobs as $job) {
            $job->handle(app(GeminiDocumentReader::class));
        }
        (new FinalizeDocumentExtraction($scan->id, 2, array_map(
            fn (ProcessDocumentBatch $job): array => ['start' => $job->startPage, 'end' => $job->endPage],
            $jobs
        )))->handle(app(GeminiDocumentReader::class), app(StoreDocumentFindings::class));

        $this->assertSame(['married'], $scan->fresh()->findings->observations['fields'][0]['normalized_value']);
        Http::assertSentCount(3);
    }

    public function test_model_change_invalidates_cached_page_results(): void
    {
        $scan = $this->readableScan();
        $this->putPdf($scan, 2);
        config()->set('document_scanner.pages_per_batch', 1);
        $reader = app(GeminiDocumentReader::class);
        $reader->preparePdfBatches($scan);
        $path = 'document-scans/'.$scan->id.'/extraction-batches/1.json';
        Storage::disk('local')->put($path, '{}');

        config()->set('services.gemini.model', 'gemini-3.1-flash-lite-new-version');
        $reader->preparePdfBatches($scan);

        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertTrue(Storage::disk('local')->exists('document-scans/'.$scan->id.'/extraction-batches/1.pdf'));
    }

    public function test_batch_accepts_local_page_count_and_numbering_using_verified_pdf_range(): void
    {
        $scan = $this->readableScan();
        $this->putPdf($scan, 4);
        $reader = app(GeminiDocumentReader::class);
        $reader->preparePdfBatches($scan);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($scan) {
            preg_match('/original pages (\d+) through/', $request['input'][1]['text'], $matches);
            $start = (int) $matches[1];
            $payload = $this->batchPayload($scan, $start);
            $payload['document']['page_count'] = 2;
            $payload['pages'] = array_map(fn (int $page): array => ['number' => $page, 'reading_status' => 'complete', 'section_ids' => [], 'issues' => []], [1, 2]);
            $payload['entities'][0]['page_numbers'] = [1];
            $payload['fields'][0]['evidence']['page_number'] = 1;

            return Http::response(['status' => 'completed', 'output_text' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        });

        foreach ([1, 3] as $start) {
            $reader->extractPdfBatch($scan, 4, $start, $start + 1);
            $result = json_decode(Storage::disk('local')->get('document-scans/'.$scan->id.'/extraction-batches/'.$start.'.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(4, $result['document']['page_count']);
            $this->assertSame([$start, $start + 1], array_column($result['pages'], 'number'));
            $this->assertSame([$start], $result['entities'][0]['page_numbers']);
            $this->assertSame($start, $result['fields'][0]['evidence']['page_number']);
        }
    }

    /** @return array<string, mixed> */
    private function batchPayload(DocumentScan $scan, int $start): array
    {
        $payload = DocumentFindingsFixtures::identity($scan->id);
        $payload['document']['page_count'] = 14;
        $payload['pages'] = array_map(fn (int $page): array => ['number' => $page, 'reading_status' => 'complete', 'section_ids' => [], 'issues' => []], range($start, $start + 1));
        $payload['entities'][0]['page_numbers'] = [$start];
        $payload['fields'] = [array_replace($payload['fields'][0], ['evidence' => ['page_number' => $start]])];

        return $payload;
    }

    /** @return list<ProcessDocumentBatch> */
    private function batchedJobs(): array
    {
        $jobs = [];
        Bus::assertBatched(function (PendingBatch $batch) use (&$jobs): bool {
            $jobs = collect($batch->jobs)->all();

            return true;
        });

        return $jobs;
    }

    /** @param list<ProcessDocumentBatch> $jobs */
    private function finalizeJobs(DocumentScan $scan, array $jobs): void
    {
        (new FinalizeDocumentExtraction($scan->id, 14, array_map(
            fn (ProcessDocumentBatch $job): array => ['start' => $job->startPage, 'end' => $job->endPage],
            $jobs
        )))->handle(app(GeminiDocumentReader::class), app(StoreDocumentFindings::class));
    }

    /** @param list<ProcessDocumentBatch> $jobs */
    private function scanId(array $jobs): int
    {
        return $jobs[0]->documentScanId;
    }

    public function test_visual_disagreement_is_preserved_as_ambiguity(): void
    {
        $scan = $this->readableScan();
        $payload = DocumentFindingsFixtures::complexBlankForm($scan->id);
        $verification = ['coverage_gaps' => [], 'groups' => array_map(fn (array $field): array => [
            'id' => $field['id'],
            'options' => array_map(fn (array $option): array => [
                'id' => $option['id'],
                'mark_state' => $field['id'] === 'fatca' && $option['id'] === 'no' ? 'marked' : $option['mark_state'],
            ], $field['options']),
        ], array_values(array_filter($payload['fields'], fn (array $field): bool => $field['type'] === 'choice')))];
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['status' => 'completed', 'output_text' => json_encode($payload, JSON_THROW_ON_ERROR)])
            ->push(['status' => 'completed', 'output_text' => json_encode($verification, JSON_THROW_ON_ERROR)])]);

        $this->process($scan);

        $this->assertSame('completed', $scan->fresh()->extraction_status);
        $stored = $scan->fresh()->findings->observations;
        $fatca = collect($stored['fields'])->firstWhere('id', 'fatca');
        $this->assertSame('ambiguous', $fatca['answer_status']);
        $this->assertNull($fatca['normalized_value']);
        $this->assertSame('ambiguous', $fatca['options'][1]['mark_state']);
        $this->assertSame('conflicting_observation', $stored['issues'][0]['type']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_contains($request['input'][1]['text'], 'Visually reread')
            && str_contains($request['input'][1]['text'], '"id":"fatca"')
            && $request['input'][0]['data'] === base64_encode(Storage::disk('local')->get($scan->storage_path)));
    }

    public function test_missing_visual_option_fails_without_storing_findings(): void
    {
        $scan = $this->readableScan();
        $payload = DocumentFindingsFixtures::complexBlankForm($scan->id);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['status' => 'completed', 'output_text' => json_encode($payload, JSON_THROW_ON_ERROR)])
            ->push(['status' => 'completed', 'output_text' => json_encode(['groups' => []], JSON_THROW_ON_ERROR)])]);

        $this->process($scan);

        $this->assertSame('failed', $scan->fresh()->extraction_status);
        $this->assertNull($scan->fresh()->findings);
    }

    public function test_detected_coverage_gap_is_not_stored(): void
    {
        $scan = $this->readableScan();
        $payload = DocumentFindingsFixtures::complexBlankForm($scan->id);
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['status' => 'completed', 'output_text' => json_encode($payload, JSON_THROW_ON_ERROR)])
            ->push(['status' => 'completed', 'output_text' => json_encode(['coverage_gaps' => ['Unrecorded related-person question'], 'groups' => []], JSON_THROW_ON_ERROR)])]);

        $this->process($scan);

        $this->assertSame('failed', $scan->fresh()->extraction_status);
        $this->assertNull($scan->fresh()->findings);
    }

    #[DataProvider('documentTypes')]
    public function test_gemini_findings_are_validated_and_stored(string $fixture): void
    {
        $scan = $this->readableScan();
        $payload = DocumentFindingsFixtures::$fixture($scan->id);
        $customer = Customer::factory()->create();
        $customerBefore = $customer->fresh()->getAttributes();
        $this->fakeGemini($payload);

        $this->process($scan);

        $this->assertSame('completed', $scan->fresh()->extraction_status);
        $this->assertSame($payload, $scan->fresh()->findings->observations);
        $this->assertSame('Phase 1 summary', $scan->fresh()->summary);
        $this->assertSame($customerBefore, $customer->fresh()->getAttributes());
        Http::assertSentCount(collect($payload['fields'])->contains(fn (array $field): bool => $field['type'] === 'choice') ? 2 : 1);
        Http::assertSent(fn ($request): bool => $request['model'] === 'gemini-3.1-flash-lite'
            && $request['input'][0]['type'] === 'document'
            && $request['input'][0]['data'] === base64_encode(Storage::disk('local')->get($scan->storage_path))
            && ($request['response_format']['schema']['properties']['fields']['items']['properties']['options']['items']['properties']['mark_state']['enum'] ?? null) === ['marked', 'unmarked', 'ambiguous', 'unreadable']);
    }

    public function test_extraction_uses_configured_gemini_model_not_prior_scan_model(): void
    {
        $scan = $this->readableScan();
        $scan->update(['gemini_model' => 'gemini-3.5-flash']);
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite-from-env');
        $payload = DocumentFindingsFixtures::identity($scan->id);
        $this->fakeGemini($payload);

        $this->process($scan);

        $this->assertSame('gemini-3.1-flash-lite-from-env', $scan->fresh()->gemini_model);
        Http::assertSent(fn ($request): bool => $request['model'] === 'gemini-3.1-flash-lite-from-env');
    }

    public function test_multiple_pages_and_spanning_section_are_stored(): void
    {
        $scan = $this->readableScan();
        $this->putPdf($scan, 2);
        $payload = DocumentFindingsFixtures::application($scan->id);
        $payload['document']['page_count'] = 2;
        $payload['pages'][] = ['number' => 2, 'reading_status' => 'complete', 'section_ids' => ['personal'], 'issues' => []];
        $payload['sections'][0]['page_numbers'] = [1, 2];
        $payload['fields'][0]['evidence']['page_number'] = 2;
        $this->fakeGemini($payload);

        $this->process($scan);

        $this->assertSame([1, 2], array_column($scan->fresh()->findings->observations['pages'], 'number'));
        $this->assertSame(2, $scan->fresh()->findings->observations['fields'][0]['evidence']['page_number']);
    }

    public function test_missing_or_partial_pages_do_not_complete_extraction(): void
    {
        foreach (['missing', 'partial'] as $case) {
            $scan = $this->readableScan();
            $payload = DocumentFindingsFixtures::identity($scan->id);
            if ($case === 'missing') {
                $payload['document']['page_count'] = 2;
            } else {
                $payload['document']['reading_status'] = 'partial';
                $payload['pages'][0]['reading_status'] = 'partial';
            }
            $this->fakeGemini($payload);

            $this->process($scan);

            $this->assertSame('failed', $scan->fresh()->extraction_status);
            $this->assertStringContainsString(
                'Detailed extraction could not be completed because the AI returned an incomplete or invalid result.',
                $scan->fresh()->extraction_error
            );
            $this->assertStringContainsString('Gemini did not read every document page.', $scan->fresh()->extraction_error);
            $this->assertNull($scan->fresh()->findings);
            $this->assertSame(DocumentScanStatus::Completed, $scan->fresh()->status);
        }
    }

    public function test_invalid_json_and_schema_do_not_persist_findings(): void
    {
        foreach (['{invalid', json_encode(['schema_version' => '1.0'])] as $text) {
            $scan = $this->readableScan();
            $this->fakeGeminiText($text);

            $this->process($scan);

            $this->assertSame('failed', $scan->fresh()->extraction_status);
            $this->assertStringContainsString(
                'Detailed extraction could not be completed because the AI returned an incomplete or invalid result.',
                $scan->fresh()->extraction_error
            );
            $this->assertNull($scan->fresh()->findings);
        }
    }

    public function test_transient_ai_failure_marks_failed_without_auto_retry_or_erasing_phase_one(): void
    {
        $scan = $this->readableScan();
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['status' => 'UNAVAILABLE']], 503)]);
        $job = new ProcessDocumentExtraction($scan->id);

        $this->process($scan, $job);

        $this->assertSame('failed', $scan->fresh()->extraction_status);
        $this->assertStringContainsString(
            'Detailed extraction could not be completed because the AI service was temporarily unavailable (HTTP 503). Please retry.',
            $scan->fresh()->extraction_error
        );
        $this->assertStringContainsString('API status: UNAVAILABLE', $scan->fresh()->extraction_error);
        $this->assertSame('Phase 1 summary', $scan->fresh()->summary);
        $this->assertSame(DocumentScanStatus::Completed, $scan->fresh()->status);
        $this->assertNull($scan->fresh()->findings);
        $this->assertSame(1, $job->tries);
        Http::assertSentCount(1);
    }

    public function test_duplicate_job_preserves_original_findings(): void
    {
        $scan = $this->readableScan();
        $payload = DocumentFindingsFixtures::identity($scan->id);
        $this->fakeGemini($payload);
        $this->process($scan);
        $this->process($scan);

        $this->assertDatabaseCount('document_findings', 1);
        $this->assertSame($payload, $scan->fresh()->findings->observations);
        Http::assertSentCount(1);
    }

    public function test_readable_document_extracts_a_profile_in_one_request(): void
    {
        Storage::fake('local');
        Queue::fake([ProcessDocumentExtraction::class]);
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite');
        $scan = DocumentScan::factory()->create();
        $this->putPdf($scan);
        $this->fakeGemini([
            'contract_version' => '2.0', 'schema_hash' => CustomerProfileSchema::hash(),
            'document_type' => 'Letter', 'title' => null, 'summary' => 'A letter.',
            'readable' => true, 'limitations' => null, 'unresolved' => [],
            'records' => [[
                'record_ref' => 'r1', 'anchor' => 'Letter', 'source_pages' => [1], 'warnings' => [],
                'values' => [[
                    'field_id' => 'customer.full_legal_name', 'entity_ref' => 'person-1', 'entity_role' => 'Applicant',
                    'row_ref' => null, 'state' => 'provided', 'value' => 'Amina Noor',
                    'source_page' => 1, 'evidence_text' => 'Amina Noor',
                ]],
            ]],
        ]);

        (new ProcessDocumentScan($scan->id))->handle(app(GeminiDocumentReader::class));

        $this->assertSame(DocumentScanStatus::Completed, $scan->fresh()->status);
        $this->assertSame(1, $scan->extractedProfiles()->count());
        Http::assertSentCount(1);
        Queue::assertNotPushed(ProcessDocumentExtraction::class);
    }

    public function test_unreadable_phase_one_result_does_not_dispatch_extraction(): void
    {
        Storage::fake('local');
        Queue::fake([ProcessDocumentExtraction::class]);
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite');
        $scan = DocumentScan::factory()->create();
        Storage::disk('local')->put($scan->storage_path, 'image bytes');
        $this->fakeGemini(['document_type' => null, 'title' => null, 'summary' => null, 'readable' => false, 'limitations' => 'Blurry', 'records' => []]);

        (new ProcessDocumentScan($scan->id))->handle(app(GeminiDocumentReader::class));

        $this->assertSame('failed', $scan->fresh()->extraction_status);
        $this->assertSame(
            'Detailed extraction could not run because the document was not readable.',
            $scan->fresh()->extraction_error
        );
        Queue::assertNotPushed(ProcessDocumentExtraction::class);
    }

    public function test_failed_extraction_can_be_retried_from_detail_page(): void
    {
        Storage::fake('local');
        Queue::fake([ProcessDocumentExtraction::class]);
        $staff = User::factory()->staff()->create();
        $scan = DocumentScan::factory()->for($staff, 'uploader')->create([
            'status' => DocumentScanStatus::Completed,
            'readable' => true,
            'extraction_status' => 'failed',
            'extraction_error' => 'Detailed extraction could not be completed because the AI service was temporarily unavailable (HTTP 503). Please retry.',
        ]);
        Storage::disk('local')->put($scan->storage_path, '%PDF-1.4 original');

        Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])
            ->call('retryExtraction')
            ->assertSee('Pending');

        $this->assertSame('not_started', $scan->fresh()->extraction_status);
        $this->assertNull($scan->fresh()->extraction_error);
        $this->assertSame(DocumentScanStatus::Completed, $scan->fresh()->status);
        Queue::assertPushed(ProcessDocumentExtraction::class, fn (ProcessDocumentExtraction $job): bool => $job->documentScanId === $scan->id);
    }

    public function test_completed_extraction_cannot_be_retried(): void
    {
        Queue::fake([ProcessDocumentExtraction::class]);
        $staff = User::factory()->staff()->create();
        $scan = DocumentScan::factory()->for($staff, 'uploader')->create([
            'status' => DocumentScanStatus::Completed,
            'readable' => true,
            'extraction_status' => 'completed',
        ]);

        Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])
            ->call('retryExtraction');

        $this->assertSame('completed', $scan->fresh()->extraction_status);
        Queue::assertNotPushed(ProcessDocumentExtraction::class);
    }

    public function test_image_extraction_uses_original_image_and_detail_page_shows_result(): void
    {
        $scan = $this->readableScan();
        $scan->update(['mime_type' => 'image/png']);
        $payload = DocumentFindingsFixtures::identity($scan->id);
        $this->fakeGemini($payload);

        $this->process($scan);

        Http::assertSent(fn ($request): bool => $request['input'][0]['type'] === 'image'
            && $request['input'][0]['mime_type'] === 'image/png');
        $this->actingAs(User::query()->findOrFail($scan->uploaded_by))
            ->get(route('documents.show', $scan))
            ->assertOk()
            ->assertSee('Detailed extraction')
            ->assertSee('Completed')
            ->assertSee('2 findings stored.')
            ->assertSee('Entities')
            ->assertSee('Amina Noor')
            ->assertSee('Holder')
            ->assertSee('Extracted fields')
            ->assertSee('Full name')
            ->assertSee('Date of birth')
            ->assertSee('1990-04-02');
    }

    public function test_detail_page_shows_sectioned_fields_tables_and_content(): void
    {
        $scan = $this->readableScan();
        $payload = DocumentFindingsFixtures::application($scan->id);
        $payload['tables'][] = [
            'id' => 'income',
            'section_id' => 'personal',
            'entity_id' => 'applicant',
            'columns' => [
                ['id' => 'year', 'heading' => 'Year'],
                ['id' => 'amount', 'heading' => 'Amount'],
            ],
            'rows' => [[
                'id' => 'row-1',
                'cells' => [
                    ['column_id' => 'year', 'raw_value' => '2025', 'type' => 'text', 'normalized_value' => '2025', 'notes' => null],
                    ['column_id' => 'amount', 'raw_value' => 'RM 100', 'type' => 'currency', 'normalized_value' => ['amount' => '100.00', 'currency' => 'MYR'], 'notes' => null],
                ],
            ]],
            'evidence' => ['page_number' => 1],
        ];
        $payload['content_blocks'][] = [
            'id' => 'decl-1',
            'type' => 'declaration',
            'text' => 'I declare the information is true.',
            'section_id' => 'personal',
            'entity_id' => 'applicant',
            'evidence' => ['page_number' => 1],
        ];
        $this->fakeGemini($payload);
        $this->process($scan);

        $this->actingAs(User::query()->findOrFail($scan->uploaded_by))
            ->get(route('documents.show', $scan))
            ->assertOk()
            ->assertSee('Personal details')
            ->assertSee('Applicant name')
            ->assertSee('Maya Lim')
            ->assertSee('Marital status')
            ->assertSee('Married')
            ->assertSee('Table: income')
            ->assertSee('Year')
            ->assertSee('MYR 100.00')
            ->assertSee('Content')
            ->assertSee('I declare the information is true.');
    }

    public function test_failed_extraction_detail_page_shows_error_and_retry(): void
    {
        $scan = $this->readableScan();
        $scan->update([
            'extraction_status' => 'failed',
            'extraction_error' => 'Detailed extraction could not be completed because the AI service was temporarily unavailable (HTTP 503). Please retry.',
        ]);

        $this->actingAs(User::query()->findOrFail($scan->uploaded_by))
            ->get(route('documents.show', $scan))
            ->assertOk()
            ->assertSee('Detailed extraction')
            ->assertSee('Failed')
            ->assertSee('Detailed extraction did not complete')
            ->assertSee('Basic document understanding finished successfully')
            ->assertSee('AI service was temporarily unavailable (HTTP 503)')
            ->assertSee('Retry extraction');
    }

    private function readableScan(): DocumentScan
    {
        Storage::fake('local');
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite');
        $scan = DocumentScan::factory()->create([
            'status' => DocumentScanStatus::Completed, 'readable' => true, 'summary' => 'Phase 1 summary',
        ]);
        $this->putPdf($scan);

        return $scan;
    }

    private function putPdf(DocumentScan $scan, int $pages = 1): void
    {
        $scan->update(['record_plan' => [[
            'anchor' => 'Test application',
            'regions' => array_map(fn (int $page): array => ['page_number' => $page, 'bbox' => null], range(1, $pages)),
        ]]]);
        $pdf = new FPDF;
        for ($page = 1; $page <= $pages; $page++) {
            $pdf->AddPage();
            $pdf->SetFont('Arial', '', 12);
            $pdf->Cell(0, 10, 'Page '.$page);
        }
        Storage::disk('local')->put($scan->storage_path, $pdf->Output('S'));
    }

    /** @param array<string, mixed> $payload */
    private function fakeGemini(array $payload): void
    {
        $choices = array_values(array_filter($payload['fields'] ?? [], fn (array $field): bool => ($field['type'] ?? null) === 'choice'));
        if ($choices === []) {
            $this->fakeGeminiText(json_encode($payload, JSON_THROW_ON_ERROR));

            return;
        }

        $verification = ['coverage_gaps' => [], 'groups' => array_map(fn (array $field): array => [
            'id' => $field['id'],
            'options' => array_map(fn (array $option): array => ['id' => $option['id'], 'mark_state' => $option['mark_state']], $field['options']),
        ], $choices)];
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['status' => 'completed', 'output_text' => json_encode($payload, JSON_THROW_ON_ERROR)])
            ->push(['status' => 'completed', 'output_text' => json_encode($verification, JSON_THROW_ON_ERROR)])]);
    }

    private function fakeGeminiText(string $text): void
    {
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed', 'output_text' => $text,
        ])]);
    }

    private function process(DocumentScan $scan, ?ProcessDocumentExtraction $job = null): void
    {
        ($job ?? new ProcessDocumentExtraction($scan->id))->handle(
            app(GeminiDocumentReader::class), app(StoreDocumentFindings::class)
        );
    }
}
