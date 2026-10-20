<?php

namespace Tests\Feature;

use App\Enums\DocumentScanStatus;
use App\Jobs\ProcessDocumentScan;
use App\Livewire\Documents\Index;
use App\Livewire\Documents\Show;
use App\Models\Customer;
use App\Models\CustomerProfileSchemaSnapshot;
use App\Models\DocumentScan;
use App\Models\User;
use App\Services\CustomerProfileSchema;
use App\Services\GeminiDocumentReader;
use App\Services\SinglePassExtraction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_upload_pdf_privately_without_a_customer_and_dispatch_processing(): void
    {
        Storage::fake('local');
        Queue::fake();
        $staff = User::factory()->staff()->create();

        $component = Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('document', UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf'));

        preg_match('/<button type="submit"[^>]*>/', $component->html(), $submit);
        $this->assertDoesNotMatchRegularExpression('/\sdisabled(?=[\s=>])/', $submit[0] ?? '');

        $component->call('processDocument')
            ->assertHasNoErrors()
            ->assertRedirect();

        $scan = DocumentScan::query()->sole();
        $this->assertSame(DocumentScanStatus::Pending, $scan->status);
        $this->assertSame($staff->id, $scan->uploaded_by);
        $this->assertSame('statement.pdf', $scan->original_filename);
        $this->assertSame('application/pdf', $scan->mime_type);
        $this->assertSame(CustomerProfileSchema::hash(), $scan->profile_schema_hash);
        $this->assertSame(CustomerProfileSchema::canonicalJson(), CustomerProfileSchemaSnapshot::query()->where('hash', $scan->profile_schema_hash)->sole()->canonical_json);
        $this->assertStringStartsWith('document-scans/', $scan->storage_path);
        Storage::disk('local')->assertExists($scan->storage_path);
        $this->assertDatabaseCount('customers', 0);
        Queue::assertPushed(ProcessDocumentScan::class, fn (ProcessDocumentScan $job): bool => $job->documentScanId === $scan->id);
    }

    public function test_staff_can_upload_jpeg_and_png(): void
    {
        Storage::fake('local');
        Queue::fake();
        $staff = User::factory()->staff()->create();

        foreach (['photo.jpg' => 'image/jpeg', 'scan.png' => 'image/png'] as $name => $mimeType) {
            Livewire::actingAs($staff)
                ->test(Index::class)
                ->set('document', UploadedFile::fake()->create($name, 100, $mimeType))
                ->call('processDocument')
                ->assertHasNoErrors();
        }

        $this->assertDatabaseCount('document_scans', 2);
    }

    public function test_unsupported_or_disguised_files_are_rejected(): void
    {
        Storage::fake('local');
        Queue::fake();
        $staff = User::factory()->staff()->create();

        foreach ([
            UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            UploadedFile::fake()->create('disguised.jpg', 10, 'text/plain'),
        ] as $file) {
            Livewire::actingAs($staff)
                ->test(Index::class)
                ->set('document', $file)
                ->call('processDocument')
                ->assertHasErrors('document');
        }

        $this->assertDatabaseCount('document_scans', 0);
        Queue::assertNothingPushed();
    }

    public function test_oversized_file_is_rejected(): void
    {
        Storage::fake('local');
        Queue::fake();
        $staff = User::factory()->staff()->create();

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('document', UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf'))
            ->call('processDocument')
            ->assertHasErrors('document');

        $this->assertDatabaseCount('document_scans', 0);
    }

    public function test_guests_and_inactive_users_cannot_access_documents(): void
    {
        $scan = DocumentScan::factory()->create();

        $this->get(route('documents.index'))->assertRedirect(route('login'));
        $this->get(route('documents.show', $scan))->assertRedirect(route('login'));
        $this->get(route('documents.file', $scan))->assertRedirect(route('login'));

        $inactive = User::factory()->inactive()->create();
        $this->actingAs($inactive)->get(route('documents.index'))->assertRedirect(route('login'));
        $this->actingAs($inactive)->get(route('documents.file', $scan))->assertRedirect(route('login'));
    }

    public function test_authorized_staff_can_download_and_preview_a_private_original(): void
    {
        Storage::fake('local');
        $staff = User::factory()->staff()->create();
        $scan = DocumentScan::factory()->for($staff, 'uploader')->create();
        Storage::disk('local')->put($scan->storage_path, '%PDF-1.4 private');

        $this->actingAs($staff)->get(route('documents.file', [$scan, 'download' => 1]))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=sample.pdf');

        $this->actingAs($staff)->get(route('documents.file', $scan))
            ->assertOk()
            ->assertHeader('content-disposition', 'inline; filename=sample.pdf');

        $this->actingAs($staff)->get(route('documents.show', $scan))
            ->assertOk()
            ->assertSee('Download original')
            ->assertSee('Preview')
            ->assertSee('Rescan')
            ->assertSee(route('documents.file', [$scan, 'download' => 1]), false)
            ->assertSee(route('documents.file', $scan), false)
            ->assertSee('target="_blank"', false);

        $this->assertFalse(Storage::disk('public')->exists($scan->storage_path));
    }

    public function test_gemini_success_stores_only_basic_document_result(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite');
        $scan = DocumentScan::factory()->create();
        $customer = Customer::factory()->create();
        $customerBefore = $customer->fresh()->getAttributes();
        Storage::disk('local')->put($scan->storage_path, '%PDF-1.4 sample');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed',
            'steps' => [[
                'type' => 'model_output',
                'content' => [['type' => 'text', 'text' => json_encode([
                    'contract_version' => '2.0',
                    'schema_hash' => CustomerProfileSchema::hash(),
                    'document_type' => 'Bank Statement',
                    'title' => 'Monthly Statement',
                    'summary' => 'A statement for one month.',
                    'readable' => true,
                    'limitations' => null,
                    'records' => [[
                        'record_ref' => 'r1',
                        'anchor' => 'Statement account',
                        'source_pages' => [1],
                        'values' => [[
                            'field_id' => 'customer.full_legal_name',
                            'entity_ref' => 'person-1',
                            'entity_role' => 'Applicant',
                            'row_ref' => null,
                            'state' => 'provided',
                            'value' => 'Amina Noor',
                            'source_page' => 1,
                            'evidence_text' => 'Name: Amina Noor',
                            'bbox' => ['x' => 116, 'y' => 91, 'width' => 866, 'height' => 738],
                        ]],
                        'warnings' => [],
                    ]],
                    'unresolved' => [],
                ])]],
            ]],
        ])]);

        (new ProcessDocumentScan($scan->id))->handle(app(GeminiDocumentReader::class));

        $scan->refresh();
        $this->assertSame(DocumentScanStatus::Completed, $scan->status);
        $this->assertSame('gemini-3.1-flash-lite', $scan->gemini_model);
        $this->assertSame('Bank Statement', $scan->document_type);
        $this->assertSame('Monthly Statement', $scan->document_title);
        $this->assertSame('A statement for one month.', $scan->summary);
        $this->assertTrue($scan->readable);
        $this->assertSame('Statement account', $scan->record_plan[0]['anchor']);
        $this->assertNull($scan->record_plan[0]['regions'][0]['bbox']);
        $this->assertSame('Amina Noor', $scan->extractedProfiles()->sole()->original_projection['values'][0]['value']);
        $this->assertNotNull($scan->processing_started_at);
        $this->assertNotNull($scan->processing_completed_at);
        $this->assertSame($customerBefore, $customer->fresh()->getAttributes());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->hasHeader('x-goog-api-key', 'test-key')
            && $request['model'] === 'gemini-3.1-flash-lite'
            && $request['store'] === false
            && $request['input'][0]['type'] === 'document'
            && $request['input'][0]['mime_type'] === 'application/pdf'
            && str_contains($request['input'][1]['text'], 'customer.full_legal_name')
            && in_array('contract_version', $request['response_format']['schema']['required'], true));
    }

    public function test_invalid_gemini_result_fails_without_customer_changes(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config()->set('services.gemini.api_key', 'test-key');
        $scan = DocumentScan::factory()->create();
        Storage::disk('local')->put($scan->storage_path, '%PDF-1.4 sample');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed',
            'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"summary":"partial"}']]]],
        ])]);

        (new ProcessDocumentScan($scan->id))->handle(app(GeminiDocumentReader::class));

        $this->assertSame(DocumentScanStatus::Failed, $scan->fresh()->status);
        $this->assertNull($scan->fresh()->summary);
        $this->assertDatabaseCount('customers', 0);
        Storage::disk('local')->assertExists($scan->storage_path);
    }

    public function test_image_request_uses_image_input_and_model_override(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite-test');
        $scan = DocumentScan::factory()->create(['mime_type' => 'image/png']);
        Storage::disk('local')->put($scan->storage_path, 'png bytes');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed',
            'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => json_encode([
                'document_type' => null, 'title' => null, 'summary' => null,
                'readable' => false, 'limitations' => 'Image is blurry.', 'records' => [],
            ])]]]],
        ])]);

        (new ProcessDocumentScan($scan->id))->handle(app(GeminiDocumentReader::class));

        $this->assertSame(DocumentScanStatus::Completed, $scan->fresh()->status);
        $this->assertSame('gemini-3.1-flash-lite-test', $scan->fresh()->gemini_model);
        Http::assertSent(fn ($request): bool => $request['input'][0]['type'] === 'image'
            && $request['input'][0]['mime_type'] === 'image/png');
    }

    public function test_document_pages_show_status_and_escaped_summary(): void
    {
        $staff = User::factory()->staff()->create();
        $scan = DocumentScan::factory()->for($staff, 'uploader')->create([
            'status' => DocumentScanStatus::Completed,
            'summary' => '<script>alert(1)</script>',
            'readable' => true,
        ]);

        $this->actingAs($staff)->get(route('documents.index'))
            ->assertOk()
            ->assertSee('sample.pdf')
            ->assertSee('Waiting for extraction');
        $this->actingAs($staff)->get(route('documents.show', $scan))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_pending_document_detail_shows_processing_state(): void
    {
        $staff = User::factory()->staff()->create();
        $scan = DocumentScan::factory()->for($staff, 'uploader')->create();

        $this->actingAs($staff)->get(route('documents.show', $scan))
            ->assertOk()
            ->assertSee('Pending')
            ->assertSee('Waiting for the reader to start')
            ->assertSee('This page refreshes automatically.')
            ->assertDontSee('Processing is underway.');
    }

    public function test_processing_document_shows_how_many_page_groups_are_read(): void
    {
        Storage::fake('local');
        $staff = User::factory()->staff()->create();
        $scan = DocumentScan::factory()->for($staff, 'uploader')->create([
            'status' => DocumentScanStatus::Processing,
            'profile_schema_hash' => CustomerProfileSchema::snapshot()->hash,
        ]);
        Storage::disk('local')->put('document-scans/'.$scan->id.'/read-progress.json', json_encode([
            'done' => 2,
            'total' => 5,
            'label' => 'Reading pages 5–6',
        ]));

        $this->actingAs($staff)->get(route('documents.show', $scan))
            ->assertOk()
            ->assertSee('Reading pages 5–6')
            ->assertSee('2 of 5')
            ->assertDontSee('No listed field was filled.')
            ->assertDontSee('Processing is underway.');
    }

    public function test_incomplete_gemini_interaction_is_not_accepted_as_a_result(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config()->set('services.gemini.api_key', 'test-key');
        $scan = DocumentScan::factory()->create();
        Storage::disk('local')->put($scan->storage_path, '%PDF-1.4 sample');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'incomplete',
            'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{}']]]],
        ])]);

        (new ProcessDocumentScan($scan->id))->handle(app(GeminiDocumentReader::class));

        $this->assertSame(DocumentScanStatus::Failed, $scan->fresh()->status);
        $this->assertNull($scan->fresh()->summary);
    }

    public function test_gemini_http_failure_is_retryable_without_exposing_response_body(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        Log::spy();
        config()->set('services.gemini.api_key', 'test-key');
        $scan = DocumentScan::factory()->create();
        Storage::disk('local')->put($scan->storage_path, '%PDF-1.4 sample');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'error' => ['status' => 'UNAVAILABLE', 'message' => 'private upstream details'],
        ], 503, ['Retry-After' => '30'])]);
        $job = new ProcessDocumentScan($scan->id);

        try {
            $job->handle(app(GeminiDocumentReader::class));
            $this->fail('Expected an API failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringStartsWith('Gemini could not process the document.', $exception->getMessage());
            $this->assertStringContainsString('API status: UNAVAILABLE', $exception->getMessage());
            $this->assertStringContainsString('Retry after approximately 30 seconds', $exception->getMessage());
            $this->assertStringNotContainsString('private upstream details', $exception->getMessage());
        }

        Log::shouldHaveReceived('warning')->once()->with('Gemini document processing request failed.', [
            'document_scan_id' => $scan->id,
            'http_status' => 503,
            'api_error_status' => 'UNAVAILABLE',
            'retry_after_seconds' => 30,
        ]);

        $this->assertSame(DocumentScanStatus::Processing, $scan->fresh()->status);
        $this->assertTrue($job->backoff()[0] >= 30 && $job->backoff()[0] <= 45);
        $this->assertTrue($job->backoff()[1] >= 60 && $job->backoff()[1] <= 90);

        $job->failed(null);
        $this->assertStringNotContainsString('private upstream details', $scan->fresh()->processing_error);
    }

    public function test_connection_failure_remains_retryable_then_marks_final_failure(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config()->set('services.gemini.api_key', 'test-key');
        $scan = DocumentScan::factory()->create();
        Storage::disk('local')->put($scan->storage_path, '%PDF-1.4 sample');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::failedConnection()]);
        $job = new ProcessDocumentScan($scan->id);

        try {
            $job->handle(app(GeminiDocumentReader::class));
            $this->fail('Expected a connection failure.');
        } catch (ConnectionException $exception) {
            $this->assertSame(DocumentScanStatus::Processing, $scan->fresh()->status);
            $progress = json_decode(Storage::disk('local')->get('document-scans/'.$scan->id.'/read-progress.json'), true);
            $this->assertSame('Gemini did not answer for the document. The request waited 3 minutes and received nothing.', $progress['label']);
            $job->failed($exception);
        }

        $this->assertSame('Gemini did not answer for the document. The request waited 3 minutes and received nothing.', $scan->fresh()->processing_error);
        $this->assertSame(DocumentScanStatus::Failed, $scan->fresh()->status);
        Storage::disk('local')->assertExists($scan->storage_path);
    }

    public function test_permanent_gemini_error_fails_without_repeated_attempts(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config()->set('services.gemini.api_key', 'test-key');
        $scan = DocumentScan::factory()->create();
        Storage::disk('local')->put($scan->storage_path, '%PDF-1.4 sample');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'error' => ['code' => 'invalid_argument', 'message' => 'private details'],
        ], 400)]);

        (new ProcessDocumentScan($scan->id))->handle(app(GeminiDocumentReader::class));

        $this->assertSame(DocumentScanStatus::Failed, $scan->fresh()->status);
        $this->assertStringNotContainsString('private details', $scan->fresh()->processing_error);
        Http::assertSentCount(1);
        Storage::disk('local')->assertExists($scan->storage_path);
    }

    public function test_failed_scan_can_be_requeued_with_the_same_private_original(): void
    {
        Storage::fake('local');
        Queue::fake([ProcessDocumentScan::class]);
        $staff = User::factory()->staff()->create();
        $scan = DocumentScan::factory()->for($staff, 'uploader')->create([
            'status' => DocumentScanStatus::Failed,
            'processing_error' => 'Previous failure.',
            'processing_completed_at' => now(),
        ]);
        Storage::disk('local')->put($scan->storage_path, '%PDF-1.4 original');

        Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])
            ->call('retryScan');

        $this->assertSame(DocumentScanStatus::Pending, $scan->fresh()->status);
        $this->assertNull($scan->fresh()->processing_completed_at);
        $this->assertSame('%PDF-1.4 original', Storage::disk('local')->get($scan->storage_path));
        $this->assertDatabaseCount('document_scans', 1);
        Queue::assertPushed(ProcessDocumentScan::class, fn (ProcessDocumentScan $job): bool => $job->documentScanId === $scan->id);
    }

    public function test_a_processing_scan_can_be_cancelled(): void
    {
        Storage::fake('local');
        Queue::fake([ProcessDocumentScan::class]);
        $staff = User::factory()->staff()->create();
        $scan = DocumentScan::factory()->for($staff, 'uploader')->create([
            'status' => DocumentScanStatus::Processing,
        ]);
        Storage::disk('local')->put($scan->storage_path, '%PDF-1.4 original');

        Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])
            ->assertSee('Cancel scan')
            ->assertSee('Rescan')
            ->call('cancelScan');

        $this->assertSame(DocumentScanStatus::Failed, $scan->fresh()->status);
        $this->assertSame('Scan cancelled.', $scan->fresh()->processing_error);
        Queue::assertNothingPushed();
    }

    public function test_rescan_stops_the_current_read_and_queues_another(): void
    {
        Storage::fake('local');
        Queue::fake([ProcessDocumentScan::class]);
        $staff = User::factory()->staff()->create();
        $scan = DocumentScan::factory()->for($staff, 'uploader')->create([
            'status' => DocumentScanStatus::Processing,
            'processing_error' => null,
        ]);
        Storage::disk('local')->put($scan->storage_path, '%PDF-1.4 original');

        Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])
            ->call('rescan');

        $this->assertSame(DocumentScanStatus::Pending, $scan->fresh()->status);
        $this->assertNull($scan->fresh()->processing_error);
        Queue::assertPushed(ProcessDocumentScan::class, fn (ProcessDocumentScan $job): bool => $job->documentScanId === $scan->id);
    }

    public function test_rescan_clears_a_completed_document_and_queues_another(): void
    {
        Storage::fake('local');
        Queue::fake([ProcessDocumentScan::class]);
        $staff = User::factory()->staff()->create();
        $scan = DocumentScan::factory()->for($staff, 'uploader')->create([
            'status' => DocumentScanStatus::Completed,
            'document_type' => 'application',
            'document_title' => 'Old title',
            'summary' => 'Old summary',
            'readable' => true,
            'extraction_status' => 'completed',
            'profile_status' => 'completed',
            'profile_schema_hash' => 'abc',
            'ai_responses' => [['body' => ['x' => 1]]],
            'processing_completed_at' => now(),
        ]);
        Storage::disk('local')->put($scan->storage_path, '%PDF-1.4 original');
        Storage::disk('local')->put('document-scans/'.$scan->id.'/profile-batches/old.json', '{}');
        $scan->findings()->create([
            'observations' => ['fields' => [], 'tables' => [], 'content_blocks' => []],
            'schema_version' => '1',
        ]);
        $scan->extractedProfiles()->create([
            'record_ref' => 'p0001',
            'source_pages' => [1],
            'field_schema_hash' => str_repeat('a', 64),
            'field_schema_version' => '1',
            'prompt_version' => '16',
            'contract_version' => '2.0',
            'original_projection' => ['values' => []],
            'reviewed_projection' => ['values' => []],
        ]);

        Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])
            ->assertSee('Rescan')
            ->call('rescan');

        $fresh = $scan->fresh();
        $this->assertSame(DocumentScanStatus::Pending, $fresh->status);
        $this->assertNull($fresh->document_title);
        $this->assertSame('not_started', $fresh->extraction_status);
        $this->assertSame('not_started', $fresh->profile_status);
        $this->assertNull($fresh->ai_responses);
        $this->assertFalse($fresh->findings()->exists());
        $this->assertSame(0, $fresh->extractedProfiles()->count());
        $this->assertSame('%PDF-1.4 original', Storage::disk('local')->get($scan->storage_path));
        Storage::disk('local')->assertMissing('document-scans/'.$scan->id.'/profile-batches/old.json');
        Queue::assertPushed(ProcessDocumentScan::class, fn (ProcessDocumentScan $job): bool => $job->documentScanId === $scan->id);
    }

    public function test_a_cancelled_run_does_not_call_the_model_or_store_a_profile(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config()->set('services.gemini.api_key', 'test-key');
        $scan = DocumentScan::factory()->create();
        Storage::disk('local')->put($scan->storage_path, '%PDF-1.4 sample');
        Cache::put(DocumentScan::activeRunKey($scan->id), 'cancelled', now()->addHour());

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class), 'running');

        Http::assertNothingSent();
        $this->assertSame(0, $scan->extractedProfiles()->count());
        $this->assertSame(DocumentScanStatus::Pending, $scan->fresh()->status);
    }

    public function test_completed_scan_cannot_be_requeued(): void
    {
        Queue::fake([ProcessDocumentScan::class]);
        $staff = User::factory()->staff()->create();
        $scan = DocumentScan::factory()->for($staff, 'uploader')->create(['status' => DocumentScanStatus::Completed]);

        Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])
            ->call('retryScan');

        $this->assertSame(DocumentScanStatus::Completed, $scan->fresh()->status);
        Queue::assertNotPushed(ProcessDocumentScan::class);
    }

    public function test_completed_job_is_not_processed_twice(): void
    {
        Http::preventStrayRequests();
        $scan = DocumentScan::factory()->create(['status' => DocumentScanStatus::Completed]);

        (new ProcessDocumentScan($scan->id))->handle(app(GeminiDocumentReader::class));

        Http::assertNothingSent();
    }

    public function test_direct_read_ignores_page_grid_coordinates(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config()->set('services.gemini.api_key', 'test-key');
        $scan = DocumentScan::factory()->create();
        Storage::disk('local')->put($scan->storage_path, '%PDF-1.4 sample');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed',
            'output_text' => json_encode([
                'document_type' => 'Form', 'title' => null, 'summary' => 'One form.',
                'readable' => true, 'limitations' => null,
                'records' => [['anchor' => 'Applicant', 'regions' => [['page_number' => 1, 'bbox' => ['x' => 116, 'y' => 91, 'width' => 866, 'height' => 738]]]]],
            ]),
        ])]);

        $result = app(GeminiDocumentReader::class)->read($scan);

        $this->assertNull($result['record_plan'][0]['regions'][0]['bbox']);
    }

    public function test_selected_documents_can_be_deleted_together(): void
    {
        Storage::fake('local');
        $staff = User::factory()->staff()->create();
        $keep = DocumentScan::factory()->for($staff, 'uploader')->create(['original_filename' => 'keep.pdf', 'storage_path' => 'document-scans/keep.pdf']);
        $drop = DocumentScan::factory()->for($staff, 'uploader')->create(['original_filename' => 'drop.pdf', 'storage_path' => 'document-scans/drop.pdf']);
        Storage::disk('local')->put($drop->storage_path, 'pdf');
        Storage::disk('local')->put('document-scans/'.$drop->id.'/extraction-batches/1.json', '{}');

        Livewire::actingAs($staff)->test(Index::class)
            ->call('toggleAll')
            ->assertSet('selected', [(string) $keep->id, (string) $drop->id])
            ->call('toggleAll')
            ->assertSet('selected', [])
            ->set('selected', [(string) $drop->id])
            ->call('deleteSelected')
            ->assertDispatched('notify');

        $this->assertModelExists($keep);
        $this->assertModelMissing($drop);
        Storage::disk('local')->assertMissing('document-scans/drop.pdf');
        Storage::disk('local')->assertMissing('document-scans/'.$drop->id.'/extraction-batches/1.json');
    }

    public function test_scanned_document_filename_can_be_renamed(): void
    {
        Storage::fake('local');
        $staff = User::factory()->staff()->create();
        $scan = DocumentScan::factory()->for($staff, 'uploader')->create([
            'original_filename' => 'statement.pdf',
            'storage_path' => 'document-scans/opaque.pdf',
        ]);
        Storage::disk('local')->put($scan->storage_path, '%PDF-1.4 original');

        Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])
            ->assertSee('Rename')
            ->call('editFilename')
            ->assertSet('editingFilename', true)
            ->assertSet('filename', 'statement.pdf')
            ->set('filename', 'folder/client-a.pdf')
            ->call('saveFilename')
            ->assertSet('editingFilename', false)
            ->assertSee('client-a.pdf')
            ->assertDontSee('statement.pdf');

        $this->assertSame('client-a.pdf', $scan->fresh()->original_filename);
        $this->assertSame('document-scans/opaque.pdf', $scan->fresh()->storage_path);
        Storage::disk('local')->assertExists('document-scans/opaque.pdf');
    }
}
