<?php

namespace Tests\Feature;

use App\Enums\DocumentScanStatus;
use App\Jobs\ProcessProfileExtraction;
use App\Livewire\Documents\Show;
use App\Models\Customer;
use App\Models\DocumentFinding;
use App\Models\DocumentScan;
use App\Models\User;
use App\Services\CustomerProfileSchema;
use App\Services\GeminiDocumentReader;
use App\Services\ProfileProjection;
use App\Services\StoreDocumentFindings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Fixtures\DocumentFindingsFixtures;
use Tests\TestCase;

class ProfileExtractionTest extends TestCase
{
    use RefreshDatabase;

    public function test_findings_queue_a_separate_profile_pass_and_store_an_unlinked_profile(): void
    {
        Queue::fake([ProcessProfileExtraction::class]);
        $scan = $this->scan();
        $customer = Customer::factory()->create();
        $before = $customer->fresh()->getAttributes();
        $original = $this->findings($scan);
        app(StoreDocumentFindings::class)->store($scan, $original);
        Queue::assertPushed(ProcessProfileExtraction::class, fn ($job): bool => $job->documentScanId === $scan->id);

        $response = $this->response($scan, [[
            'field_id' => 'customer.full_legal_name', 'entity_ref' => 'person-1', 'row_ref' => null,
            'state' => 'provided', 'value' => 'Amina Noor', 'evidence_ids' => ['name'],
        ]]);
        $this->fake($response);
        (new ProcessProfileExtraction($scan->id))->handle(app(GeminiDocumentReader::class), app(ProfileProjection::class));

        $profile = $scan->fresh()->extractedProfiles()->sole();
        $this->assertSame('completed', $scan->fresh()->profile_status, $scan->fresh()->profile_error ?? '');
        $this->assertSame('r1', $profile->record_ref);
        $this->assertSame('1', $profile->prompt_version);
        $this->assertSame(['name'], $profile->original_projection['values'][0]['evidence_ids']);
        $this->assertSame($profile->original_projection, $profile->reviewed_projection);
        $this->assertSame($original, $scan->fresh()->findings->observations);
        $this->assertEquals($before, $customer->fresh()->getAttributes());
        Http::assertSent(fn ($request): bool => $request['store'] === false
            && str_contains($request['input'][1]['text'], 'Full field schema:')
            && str_contains($request['input'][1]['text'], 'customer.full_legal_name')
            && str_contains($request['input'][1]['text'], 'Amina Noor')
            && strlen(json_encode($request->data(), JSON_THROW_ON_ERROR)) < 20_000_000
            && $request['input'][0]['data'] === base64_encode('private image'));
    }

    public function test_bad_fields_become_warnings_without_losing_valid_false_zero_or_siblings(): void
    {
        Queue::fake([ProcessProfileExtraction::class]);
        $scan = $this->scan();
        app(StoreDocumentFindings::class)->store($scan, $this->findings($scan));
        $values = [
            ['field_id' => 'customer.full_legal_name', 'entity_ref' => 'person-1', 'row_ref' => null, 'state' => 'provided', 'value' => 'Amina Noor', 'evidence_ids' => ['name']],
            ['field_id' => 'financial.monthly_income_rm', 'entity_ref' => 'person-1', 'row_ref' => null, 'state' => 'provided', 'value' => '0', 'evidence_ids' => ['name']],
            ['field_id' => 'fatca.us_person_for_tax', 'entity_ref' => 'person-1', 'row_ref' => null, 'state' => 'provided', 'value' => 'no', 'evidence_ids' => ['name']],
            ['field_id' => 'addresses[mailing].same_as_residential', 'entity_ref' => 'person-1', 'row_ref' => null, 'state' => 'provided', 'value' => false, 'evidence_ids' => ['name']],
            ['field_id' => 'customer.gender', 'entity_ref' => 'person-1', 'row_ref' => null, 'state' => 'ambiguous', 'value' => null, 'evidence_ids' => ['name']],
            ['field_id' => 'unknown.path', 'entity_ref' => 'person-1', 'row_ref' => null, 'state' => 'provided', 'value' => 'bad', 'evidence_ids' => ['name']],
            ['field_id' => 'investment.risk_tolerance', 'entity_ref' => 'person-1', 'row_ref' => null, 'state' => 'provided', 'value' => 'extreme', 'evidence_ids' => ['name']],
            ['field_id' => 'contacts[mobile].value', 'entity_ref' => 'person-1', 'row_ref' => null, 'state' => 'provided', 'value' => 'invalid', 'evidence_ids' => ['name']],
            ['field_id' => 'financial.declared_on', 'entity_ref' => 'person-1', 'row_ref' => null, 'state' => 'provided', 'value' => '9999-01-01', 'evidence_ids' => ['name']],
            ['field_id' => 'customer.gender', 'entity_ref' => 'person-1', 'row_ref' => null, 'state' => 'provided', 'value' => 'X', 'evidence_ids' => ['wrong']],
            ['field_id' => 'customer.full_legal_name', 'entity_ref' => 'person-1', 'row_ref' => null, 'state' => 'provided', 'value' => 'Conflicting', 'evidence_ids' => ['name']],
        ];
        $result = app(ProfileProjection::class)->validate($scan->fresh()->load('findings'), $this->response($scan, $values), CustomerProfileSchema::snapshot()->schema(), 1, 1);
        $this->assertCount(5, $result['records']['r1']['values']);
        $this->assertSame('0', $result['records']['r1']['values'][1]['value']);
        $this->assertSame(false, $result['records']['r1']['values'][3]['value']);
        $this->assertSame('ambiguous', $result['records']['r1']['values'][4]['state']);
        $this->assertCount(6, $result['records']['r1']['warnings']);
    }

    public function test_related_person_and_selected_choices_keep_their_ownership_and_sparse_meaning(): void
    {
        Queue::fake([ProcessProfileExtraction::class]);
        $scan = $this->scan();
        $original = DocumentFindingsFixtures::application($scan->id);
        app(StoreDocumentFindings::class)->store($scan, $original);
        $values = [
            ['field_id' => 'relationships[].related_person.full_legal_name', 'entity_ref' => 'spouse', 'row_ref' => 'relative-1',
                'state' => 'provided', 'value' => 'Lee Lim', 'evidence_ids' => ['spouse-name']],
            ['field_id' => 'fatca.us_person_for_tax', 'entity_ref' => 'applicant', 'row_ref' => null,
                'state' => 'provided', 'value' => 'no', 'evidence_ids' => ['fatca']],
            ['field_id' => 'financial.wealth_sources', 'entity_ref' => 'applicant', 'row_ref' => null,
                'state' => 'provided', 'value' => ['salary', 'savings', 'other'], 'evidence_ids' => ['wealth']],
        ];
        $result = app(ProfileProjection::class)->validate($scan->fresh()->load('findings'), $this->response($scan, $values), CustomerProfileSchema::snapshot()->schema(), 1, 1);
        $this->assertSame([], $result['records']['r1']['warnings']);
        $this->assertSame($values, $result['records']['r1']['values']);
        $this->assertCount(3, $result['records']['r1']['values']);
        $this->assertSame('unanswered', collect($scan->fresh()->findings->observations['fields'])->firstWhere('id', 'unanswered')['answer_status']);
    }

    public function test_retry_uses_validated_cache_and_keeps_original_findings(): void
    {
        Queue::fake([ProcessProfileExtraction::class]);
        $scan = $this->scan();
        app(StoreDocumentFindings::class)->store($scan, $this->findings($scan));
        $this->fake($this->response($scan, []));
        $job = new ProcessProfileExtraction($scan->id);
        $job->handle(app(GeminiDocumentReader::class), app(ProfileProjection::class));
        $scan->update(['profile_status' => 'failed']);
        $job->handle(app(GeminiDocumentReader::class), app(ProfileProjection::class));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('extracted_profiles', 1);
        $this->assertDatabaseCount('document_findings', 1);
    }

    public function test_nine_records_stay_independent_across_page_batches(): void
    {
        Storage::fake('local');
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite');
        $scan = DocumentScan::factory()->create(['status' => DocumentScanStatus::Completed, 'readable' => true,
            'profile_schema_hash' => CustomerProfileSchema::snapshot()->hash]);
        $plans = [];
        $entities = [];
        $fields = [];
        $owners = [];
        foreach (range(1, 9) as $page) {
            $plans[] = ['id' => 'r'.$page, 'anchor' => 'Applicant '.$page,
                'regions' => [['page_number' => $page, 'bbox' => null]]];
            $entities[] = ['id' => 'person-'.$page, 'type' => 'person', 'role' => 'Applicant', 'display_name' => 'Same Name',
                'related_entity_ids' => [], 'page_numbers' => [$page]];
            $fields[] = ['id' => 'name-'.$page, 'entity_id' => 'person-'.$page, 'evidence' => ['page_number' => $page], 'label' => 'Name'];
            $owners['person-'.$page] = 'r'.$page;
            $owners['name-'.$page] = 'r'.$page;
            Storage::disk('local')->put('document-scans/'.$scan->id.'/extraction-batches/'.$page.'.pdf', 'page '.$page);
        }
        config()->set('document_scanner.pages_per_batch', 1);
        DocumentFinding::query()->create(['document_scan_id' => $scan->id, 'schema_version' => '1.0',
            'observations' => ['document' => ['page_count' => 9], 'entities' => $entities, 'fields' => $fields,
                'tables' => [], 'content_blocks' => []],
            'record_ownership' => ['records' => $plans, 'findings' => $owners]]);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($scan) {
            $page = (int) str_replace('page ', '', base64_decode($request['input'][0]['data']));
            $response = ['contract_version' => '1.0', 'schema_hash' => $scan->profile_schema_hash,
                'records' => [['record_ref' => 'r'.$page, 'values' => [[
                    'field_id' => 'customer.full_legal_name', 'entity_ref' => 'person-'.$page,
                    'row_ref' => null, 'state' => 'provided', 'value' => 'Person '.$page,
                    'evidence_ids' => ['name-'.$page],
                ]], 'warnings' => []]], 'unresolved' => []];

            return Http::response(['status' => 'completed', 'output_text' => json_encode($response, JSON_THROW_ON_ERROR)]);
        });
        (new ProcessProfileExtraction($scan->id))->handle(app(GeminiDocumentReader::class), app(ProfileProjection::class));
        $this->assertSame('completed', $scan->fresh()->profile_status);
        $this->assertSame(range(1, 9), $scan->extractedProfiles()->orderBy('record_ref')->pluck('source_pages')->map(fn ($pages) => $pages[0])->all());
        $this->assertSame('Person 9', $scan->extractedProfiles()->where('record_ref', 'r9')->sole()->original_projection['values'][0]['value']);
        Http::assertSentCount(9);
    }

    public function test_invalid_batch_preserves_valid_sibling_and_reports_failure(): void
    {
        Storage::fake('local');
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite');
        config()->set('document_scanner.pages_per_batch', 1);
        $scan = DocumentScan::factory()->create(['status' => DocumentScanStatus::Completed, 'readable' => true,
            'profile_schema_hash' => CustomerProfileSchema::snapshot()->hash]);
        $plans = [];
        $entities = [];
        $fields = [];
        $owners = [];
        foreach ([1, 2] as $page) {
            $plans[] = ['id' => 'r'.$page, 'anchor' => 'Applicant '.$page, 'regions' => [['page_number' => $page, 'bbox' => null]]];
            $entities[] = ['id' => 'person-'.$page, 'role' => 'Applicant', 'page_numbers' => [$page]];
            $fields[] = ['id' => 'name-'.$page, 'entity_id' => 'person-'.$page, 'evidence' => ['page_number' => $page], 'label' => 'Name'];
            $owners['person-'.$page] = 'r'.$page;
            $owners['name-'.$page] = 'r'.$page;
            Storage::disk('local')->put('document-scans/'.$scan->id.'/extraction-batches/'.$page.'.pdf', 'page '.$page);
        }
        DocumentFinding::query()->create(['document_scan_id' => $scan->id, 'schema_version' => '1.0',
            'observations' => ['document' => ['page_count' => 2], 'entities' => $entities, 'fields' => $fields,
                'tables' => [], 'content_blocks' => []], 'record_ownership' => ['records' => $plans, 'findings' => $owners]]);
        Http::preventStrayRequests();
        $secondPageAttempts = 0;
        Http::fake(function ($request) use ($scan, &$secondPageAttempts) {
            $page = (int) str_replace('page ', '', base64_decode($request['input'][0]['data']));
            if ($page === 2) {
                $secondPageAttempts++;
            }
            $response = $page === 1
                ? ['contract_version' => '1.0', 'schema_hash' => $scan->profile_schema_hash, 'records' => [[
                    'record_ref' => 'r1', 'values' => [[
                        'field_id' => 'customer.full_legal_name', 'entity_ref' => 'person-1', 'row_ref' => null,
                        'state' => 'provided', 'value' => 'First', 'evidence_ids' => ['name-1'],
                    ]], 'warnings' => []]], 'unresolved' => []]
                : ($secondPageAttempts === 1
                    ? ['contract_version' => '1.0', 'schema_hash' => 'wrong', 'records' => [], 'unresolved' => []]
                    : ['contract_version' => '1.0', 'schema_hash' => $scan->profile_schema_hash,
                        'records' => [['record_ref' => 'r2', 'values' => [[
                            'field_id' => 'customer.full_legal_name', 'entity_ref' => 'person-2', 'row_ref' => null,
                            'state' => 'provided', 'value' => 'Second', 'evidence_ids' => ['name-2'],
                        ]], 'warnings' => []]], 'unresolved' => []]);

            return Http::response(['status' => 'completed', 'output_text' => json_encode($response, JSON_THROW_ON_ERROR)]);
        });
        (new ProcessProfileExtraction($scan->id))->handle(app(GeminiDocumentReader::class), app(ProfileProjection::class));
        $this->assertSame('failed', $scan->fresh()->profile_status);
        $this->assertStringContainsString('Pages 2-2', $scan->fresh()->profile_error);
        $this->assertSame('First', $scan->extractedProfiles()->where('record_ref', 'r1')->sole()->original_projection['values'][0]['value']);
        $this->assertDatabaseCount('document_findings', 1);

        (new ProcessProfileExtraction($scan->id))->handle(app(GeminiDocumentReader::class), app(ProfileProjection::class));
        $this->assertSame('completed', $scan->fresh()->profile_status, $scan->fresh()->profile_error ?? '');
        $this->assertSame('First', $scan->extractedProfiles()->where('record_ref', 'r1')->sole()->original_projection['values'][0]['value']);
        $this->assertSame('Second', $scan->extractedProfiles()->where('record_ref', 'r2')->sole()->original_projection['values'][0]['value']);
    }

    public function test_cross_batch_conflicts_remain_separate_and_wrong_page_evidence_is_rejected(): void
    {
        Storage::fake('local');
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite');
        config()->set('document_scanner.pages_per_batch', 1);
        $scan = DocumentScan::factory()->create(['status' => DocumentScanStatus::Completed, 'readable' => true,
            'profile_schema_hash' => CustomerProfileSchema::snapshot()->hash]);
        foreach ([1, 2] as $page) {
            Storage::disk('local')->put('document-scans/'.$scan->id.'/extraction-batches/'.$page.'.pdf', 'page '.$page);
        }
        DocumentFinding::query()->create(['document_scan_id' => $scan->id, 'schema_version' => '1.0',
            'observations' => ['document' => ['page_count' => 2],
                'entities' => [['id' => 'person', 'role' => 'Applicant', 'page_numbers' => [1, 2]]],
                'fields' => [
                    ['id' => 'name-1', 'entity_id' => 'person', 'evidence' => ['page_number' => 1], 'label' => 'Name'],
                    ['id' => 'name-2', 'entity_id' => 'person', 'evidence' => ['page_number' => 2], 'label' => 'Name'],
                ], 'tables' => [], 'content_blocks' => []],
            'record_ownership' => ['records' => [['id' => 'r1', 'anchor' => 'One applicant', 'regions' => [
                ['page_number' => 1, 'bbox' => null], ['page_number' => 2, 'bbox' => null],
            ]]], 'findings' => ['person' => 'r1', 'name-1' => 'r1', 'name-2' => 'r1']]]);
        $wrongPage = $this->response($scan, [[
            'field_id' => 'customer.full_legal_name', 'entity_ref' => 'person', 'row_ref' => null,
            'state' => 'provided', 'value' => 'Wrong page', 'evidence_ids' => ['name-2'],
        ]]);
        $invalid = app(ProfileProjection::class)->validate($scan->fresh()->load('findings'), $wrongPage, CustomerProfileSchema::snapshot()->schema(), 1, 1);
        $this->assertSame([], $invalid['records']['r1']['values']);
        $this->assertSame('Evidence ID, page, or record mismatch', $invalid['records']['r1']['warnings'][0]['issue']);

        Http::preventStrayRequests();
        Http::fake(function ($request) use ($scan) {
            $page = (int) str_replace('page ', '', base64_decode($request['input'][0]['data']));
            $response = $this->response($scan, [[
                'field_id' => 'customer.full_legal_name', 'entity_ref' => 'person', 'row_ref' => null,
                'state' => 'provided', 'value' => 'Name '.$page, 'evidence_ids' => ['name-'.$page],
            ]]);

            return Http::response(['status' => 'completed', 'output_text' => json_encode($response, JSON_THROW_ON_ERROR)]);
        });
        (new ProcessProfileExtraction($scan->id))->handle(app(GeminiDocumentReader::class), app(ProfileProjection::class));
        $profile = $scan->extractedProfiles()->sole();
        $this->assertSame(['Name 1', 'Name 2'], array_column($profile->original_projection['values'], 'value'));
        $this->assertSame('Cross-batch values require review', $profile->original_projection['warnings'][0]['issue']);
    }

    public function test_failed_profile_pass_can_be_requeued_by_staff(): void
    {
        Queue::fake([ProcessProfileExtraction::class]);
        $scan = $this->scan();
        $hash = $scan->profile_schema_hash;
        $scan->update(['profile_schema_hash' => null]);
        app(StoreDocumentFindings::class)->store($scan, $this->findings($scan));
        $scan->update(['profile_schema_hash' => $hash, 'profile_status' => 'failed', 'profile_error' => 'Temporary error']);
        $staff = User::factory()->staff()->create();
        Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])->call('retryProfileExtraction');
        $this->assertSame('not_started', $scan->fresh()->profile_status);
        Queue::assertPushed(ProcessProfileExtraction::class, 1);
    }

    public function test_scan_without_a_primary_record_does_not_make_a_profile_request(): void
    {
        $scan = $this->scan();
        DocumentFinding::query()->create(['document_scan_id' => $scan->id, 'schema_version' => '1.0',
            'observations' => ['document' => ['page_count' => 1], 'entities' => [], 'fields' => [],
                'tables' => [], 'content_blocks' => []],
            'record_ownership' => ['records' => [], 'findings' => []]]);
        Http::preventStrayRequests();
        (new ProcessProfileExtraction($scan->id))->handle(app(GeminiDocumentReader::class), app(ProfileProjection::class));
        $this->assertSame('completed', $scan->fresh()->profile_status);
        $this->assertDatabaseCount('extracted_profiles', 0);
        Http::assertNothingSent();
    }

    private function scan(): DocumentScan
    {
        Storage::fake('local');
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite');
        $scan = DocumentScan::factory()->create([
            'status' => DocumentScanStatus::Completed, 'readable' => true, 'mime_type' => 'image/png',
            'profile_schema_hash' => CustomerProfileSchema::snapshot()->hash,
            'record_plan' => [['anchor' => 'Applicant', 'regions' => [['page_number' => 1, 'bbox' => null]]]],
        ]);
        Storage::disk('local')->put($scan->storage_path, 'private image');

        return $scan;
    }

    private function findings(DocumentScan $scan): array
    {
        return DocumentFindingsFixtures::identity($scan->id);
    }

    private function response(DocumentScan $scan, array $values): array
    {
        return ['contract_version' => '1.0', 'schema_hash' => $scan->profile_schema_hash,
            'records' => [['record_ref' => 'r1', 'values' => $values, 'warnings' => []]], 'unresolved' => []];
    }

    private function fake(array $response): void
    {
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed', 'output_text' => json_encode($response, JSON_THROW_ON_ERROR),
        ])]);
    }
}
