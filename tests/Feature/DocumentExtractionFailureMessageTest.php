<?php

namespace Tests\Feature;

use App\Enums\DocumentScanStatus;
use App\Jobs\FinalizeDocumentExtraction;
use App\Jobs\ProcessDocumentBatch;
use App\Jobs\ProcessDocumentExtraction;
use App\Models\DocumentScan;
use App\Models\User;
use App\Services\GeminiDocumentReader;
use App\Services\StoreDocumentFindings;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Fixtures\DocumentFindingsFixtures;
use Tests\TestCase;
use UnexpectedValueException;

class DocumentExtractionFailureMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_incomplete_page_failure_includes_reading_status_context(): void
    {
        $scan = $this->readableScan();
        $payload = DocumentFindingsFixtures::identity($scan->id);
        $payload['document']['reading_status'] = 'partial';
        $payload['pages'][0]['reading_status'] = 'partial';
        $this->fakeGemini($payload);

        $this->process($scan);

        $error = $scan->fresh()->extraction_error;
        $this->assertSame('failed', $scan->fresh()->extraction_status);
        $this->assertStringContainsString('incomplete or invalid result', $error);
        $this->assertStringContainsString('Document reading status: partial', $error);
        $this->assertStringContainsString('page 1: partial', $error);
    }

    public function test_validation_failure_shows_concise_summary_and_logs_diagnostics(): void
    {
        Log::spy();
        $scan = $this->readableScan();
        $payload = DocumentFindingsFixtures::identity($scan->id);
        $payload['document']['reference'] = 'wrong-reference';
        $this->fakeGemini($payload);

        $this->process($scan);

        $error = $scan->fresh()->extraction_error;
        $this->assertSame('failed', $scan->fresh()->extraction_status);
        $this->assertSame(
            'Detailed extraction could not be completed because the AI result failed validation (1 issue). Check: document.reference.',
            $error
        );
        $this->assertStringNotContainsString('wrong-reference', $error);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Document extraction findings failed validation.'
                && $context['document_scan_id'] === $scan->id
                && $context['issue_count'] === 1
                && in_array('document.reference', $context['error_attributes'], true))
            ->once();
    }

    public function test_null_bbox_from_gemini_is_accepted_and_persisted_without_bbox_key(): void
    {
        $scan = $this->readableScan();
        $payload = DocumentFindingsFixtures::identity($scan->id);
        $payload['fields'][0]['evidence']['bbox'] = null;
        $payload['fields'][1]['evidence']['bbox'] = ['x' => 0.2, 'y' => 0.3, 'width' => 0.4, 'height' => 0.1];
        $this->fakeGemini($payload);

        $this->process($scan);

        $observations = $scan->fresh()->findings->observations;
        $this->assertSame('completed', $scan->fresh()->extraction_status);
        $this->assertArrayNotHasKey('bbox', $observations['fields'][0]['evidence']);
        $this->assertSame(1, $observations['fields'][0]['evidence']['page_number']);
        $this->assertSame(['x' => 0.2, 'y' => 0.3, 'width' => 0.4, 'height' => 0.1], $observations['fields'][1]['evidence']['bbox']);
    }

    public function test_permanent_runtime_failure_keeps_safe_exception_message(): void
    {
        $scan = $this->readableScan();
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'error' => ['code' => 'invalid_argument', 'message' => 'private upstream details'],
        ], 400)]);

        $this->process($scan);

        $error = $scan->fresh()->extraction_error;
        $this->assertSame('failed', $scan->fresh()->extraction_status);
        $this->assertStringContainsString('Gemini could not process the document.', $error);
        $this->assertStringContainsString('API status: invalid_argument', $error);
        $this->assertStringNotContainsString('private upstream details', $error);
    }

    public function test_failed_job_message_includes_http_status_for_transient_errors(): void
    {
        $scan = $this->readableScan();
        $job = new ProcessDocumentExtraction($scan->id);

        $job->failed(new RuntimeException('Gemini could not process the document.', 503));

        $this->assertSame('failed', $scan->fresh()->extraction_status);
        $this->assertSame(
            "Detailed extraction could not be completed because the AI service was temporarily unavailable (HTTP 503). Please retry.\n\nGemini could not process the document.",
            $scan->fresh()->extraction_error
        );
    }

    public function test_failed_page_group_identifies_its_range(): void
    {
        $scan = $this->readableScan();
        $job = new ProcessDocumentBatch($scan->id, 14, 3, 4);

        $job->failed(new UnexpectedValueException('Gemini did not read every document page in a batch.'));

        $this->assertSame('failed', $scan->fresh()->extraction_status);
        $this->assertSame(
            'Detailed extraction failed for pages 3-4. The AI returned an incomplete or invalid result. Gemini did not read every document page in a batch.',
            $scan->fresh()->extraction_error
        );
    }

    public function test_failed_page_group_shows_safe_api_status_without_response_body(): void
    {
        $scan = $this->readableScan();
        (new ProcessDocumentBatch($scan->id, 14, 3, 4))->failed(
            new RuntimeException('Gemini could not process the document. API status: INVALID_ARGUMENT.', 400)
        );

        $this->assertSame(
            'Detailed extraction failed for pages 3-4. The AI request failed (HTTP 400). Gemini could not process the document. API status: INVALID_ARGUMENT.',
            $scan->fresh()->extraction_error
        );
    }

    public function test_failed_page_group_does_not_show_document_text_from_verification(): void
    {
        $scan = $this->readableScan();
        (new ProcessDocumentBatch($scan->id, 14, 3, 4))->failed(
            new UnexpectedValueException('Visual rereading found omitted form content: private document text')
        );

        $this->assertSame(
            'Detailed extraction failed for pages 3-4. Visual rereading found omitted form content.',
            $scan->fresh()->extraction_error
        );
    }

    public function test_final_merge_failure_identifies_missing_page_group(): void
    {
        $scan = $this->readableScan();
        (new FinalizeDocumentExtraction($scan->id, 14, []))->failed(
            new UnexpectedValueException('The extraction result for pages 3-4 is missing or invalid.')
        );

        $this->assertSame(
            'Detailed extraction batches finished, but their results could not be combined. The extraction result for pages 3-4 is missing or invalid.',
            $scan->fresh()->extraction_error
        );
    }

    public function test_detail_page_shows_failure_heading_and_context(): void
    {
        $scan = $this->readableScan();
        $scan->update([
            'extraction_status' => 'failed',
            'extraction_error' => 'Detailed extraction could not be completed because the AI result failed validation (1 issue). Check: document.reference.',
        ]);

        $this->actingAs(User::query()->findOrFail($scan->uploaded_by))
            ->get(route('documents.show', $scan))
            ->assertOk()
            ->assertSee('Detailed extraction did not complete')
            ->assertSee('Basic document understanding finished successfully')
            ->assertSee('AI result failed validation (1 issue)')
            ->assertSee('Check: document.reference.')
            ->assertSee('Retry extraction');
    }

    private function readableScan(): DocumentScan
    {
        Storage::fake('local');
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite');
        $scan = DocumentScan::factory()->create([
            'status' => DocumentScanStatus::Completed,
            'readable' => true,
            'summary' => 'Phase 1 summary',
            'record_plan' => [['anchor' => 'Test application', 'regions' => [['page_number' => 1, 'bbox' => null]]]],
        ]);
        $pdf = new FPDF;
        $pdf->AddPage();
        Storage::disk('local')->put($scan->storage_path, $pdf->Output('S'));

        return $scan;
    }

    /** @param array<string, mixed> $payload */
    private function fakeGemini(array $payload): void
    {
        $this->fakeGeminiText(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function fakeGeminiText(string $text): void
    {
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed', 'output_text' => $text,
        ])]);
    }

    private function process(DocumentScan $scan): void
    {
        (new ProcessDocumentExtraction($scan->id))->handle(
            app(GeminiDocumentReader::class), app(StoreDocumentFindings::class)
        );
    }
}
