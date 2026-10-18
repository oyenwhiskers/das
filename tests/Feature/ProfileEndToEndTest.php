<?php

namespace Tests\Feature;

use App\Enums\DocumentScanStatus;
use App\Jobs\ProcessProfileExtraction;
use App\Models\Customer;
use App\Models\DocumentFinding;
use App\Models\DocumentScan;
use App\Models\ExtractedProfile;
use App\Models\User;
use App\Services\CustomerProfileSchema;
use App\Services\GeminiDocumentReader;
use App\Services\MergeExtractedProfile;
use App\Services\ProfileMergePreview;
use App\Services\ProfileProjection;
use App\Services\ReviewExtractedProfile;
use App\Services\StoreDocumentFindings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\DocumentFindingsFixtures;
use Tests\TestCase;

class ProfileEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_applicant_with_a_related_person_can_be_extracted_reviewed_and_merged(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create(['full_legal_name' => 'Maya Lim', 'race' => 'Malay', 'country_of_residence' => 'Malaysia']);
        $email = $customer->primary_email;
        Queue::fake([ProcessProfileExtraction::class]);
        $scan = $this->imageScan();
        app(StoreDocumentFindings::class)->store($scan, DocumentFindingsFixtures::application($scan->id));
        $response = ['contract_version' => '1.0', 'schema_hash' => $scan->profile_schema_hash, 'records' => [[
            'record_ref' => 'r1',
            'values' => [
                ['field_id' => 'customer.full_legal_name', 'entity_ref' => 'applicant', 'row_ref' => null, 'state' => 'provided', 'value' => 'Maya Lim', 'evidence_ids' => ['applicant-name']],
                ['field_id' => 'relationships[].related_person.full_legal_name', 'entity_ref' => 'spouse', 'row_ref' => 'relative-1', 'state' => 'provided', 'value' => 'Lee Lim', 'evidence_ids' => ['spouse-name']],
                ['field_id' => 'relationships[].relationship', 'entity_ref' => 'spouse', 'row_ref' => 'relative-1', 'state' => 'provided', 'value' => 'spouse', 'evidence_ids' => ['spouse-name']],
                ['field_id' => 'fatca.us_person_for_tax', 'entity_ref' => 'applicant', 'row_ref' => null, 'state' => 'provided', 'value' => 'no', 'evidence_ids' => ['fatca']],
            ],
            'warnings' => [],
        ]], 'unresolved' => []];
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['status' => 'completed', 'output_text' => json_encode($response, JSON_THROW_ON_ERROR)])]);
        (new ProcessProfileExtraction($scan->id))->handle(app(GeminiDocumentReader::class), app(ProfileProjection::class));
        $profile = $scan->fresh()->extractedProfiles()->sole();
        app(ReviewExtractedProfile::class)->markReviewed($profile, 0, $staff);
        $before = $scan->fresh()->findings->observations;
        $this->merge($staff, $profile->fresh(), $customer, 'fill_empty');

        $this->assertSame('Maya Lim', $customer->fresh()->full_legal_name);
        $this->assertSame('Lee Lim', $customer->relationships()->first()->relatedPerson->full_legal_name);
        $this->assertFalse($customer->fatcaDeclarations()->where('current_customer_key', $customer->id)->first()->us_person_for_tax);
        $this->assertSame($email, $customer->fresh()->primary_email);
        $this->assertSame($before, $scan->fresh()->findings->observations);
    }

    public function test_only_the_chosen_profile_from_nine_applicants_merges(): void
    {
        Storage::fake('local');
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite');
        config()->set('document_scanner.pages_per_batch', 1);
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create(['race' => 'Malay', 'country_of_residence' => 'Malaysia']);
        $scan = DocumentScan::factory()->create(['status' => DocumentScanStatus::Completed, 'readable' => true, 'profile_schema_hash' => CustomerProfileSchema::snapshot()->hash]);
        $plans = [];
        $entities = [];
        $fields = [];
        $owners = [];
        foreach (range(1, 9) as $page) {
            $plans[] = ['id' => 'r'.$page, 'anchor' => 'Applicant '.$page, 'regions' => [['page_number' => $page, 'bbox' => null]]];
            $entities[] = ['id' => 'person-'.$page, 'type' => 'person', 'role' => 'Applicant', 'display_name' => 'Same Name', 'related_entity_ids' => [], 'page_numbers' => [$page]];
            $fields[] = ['id' => 'name-'.$page, 'entity_id' => 'person-'.$page, 'evidence' => ['page_number' => $page], 'label' => 'Name'];
            $owners['person-'.$page] = 'r'.$page;
            $owners['name-'.$page] = 'r'.$page;
            Storage::disk('local')->put('document-scans/'.$scan->id.'/extraction-batches/'.$page.'.pdf', 'page '.$page);
        }
        DocumentFinding::query()->create(['document_scan_id' => $scan->id, 'schema_version' => '1.0',
            'observations' => ['document' => ['page_count' => 9], 'entities' => $entities, 'fields' => $fields, 'tables' => [], 'content_blocks' => []],
            'record_ownership' => ['records' => $plans, 'findings' => $owners]]);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($scan) {
            $page = (int) str_replace('page ', '', base64_decode($request['input'][0]['data']));
            $response = ['contract_version' => '1.0', 'schema_hash' => $scan->profile_schema_hash, 'records' => [[
                'record_ref' => 'r'.$page, 'values' => [[
                    'field_id' => 'customer.full_legal_name', 'entity_ref' => 'person-'.$page, 'row_ref' => null,
                    'state' => 'provided', 'value' => 'Person '.$page, 'evidence_ids' => ['name-'.$page],
                ]], 'warnings' => [],
            ]], 'unresolved' => []];

            return Http::response(['status' => 'completed', 'output_text' => json_encode($response, JSON_THROW_ON_ERROR)]);
        });
        (new ProcessProfileExtraction($scan->id))->handle(app(GeminiDocumentReader::class), app(ProfileProjection::class));
        $profiles = $scan->fresh()->extractedProfiles()->get();
        $this->assertCount(9, $profiles);
        foreach ($profiles as $profile) {
            app(ReviewExtractedProfile::class)->markReviewed($profile, 0, $staff);
        }
        $chosen = $profiles->firstWhere('record_ref', 'r1');
        $this->merge($staff, $chosen->fresh(), $customer, 'overwrite');

        $this->assertSame('Person 1', $customer->fresh()->full_legal_name);
        $this->assertSame(1, $chosen->merges()->count());
        $this->assertSame(0, $scan->extractedProfiles()->where('record_ref', '!=', 'r1')->whereHas('merges')->count());
    }

    public function test_one_applicant_across_batches_merges_both_pages(): void
    {
        Storage::fake('local');
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite');
        config()->set('document_scanner.pages_per_batch', 1);
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create([
            'full_legal_name' => 'Amina Noor', 'race' => 'Malay', 'country_of_residence' => 'Malaysia', 'primary_mobile' => null,
        ]);
        $scan = DocumentScan::factory()->create(['status' => DocumentScanStatus::Completed, 'readable' => true, 'profile_schema_hash' => CustomerProfileSchema::snapshot()->hash]);
        foreach ([1, 2] as $page) {
            Storage::disk('local')->put('document-scans/'.$scan->id.'/extraction-batches/'.$page.'.pdf', 'page '.$page);
        }
        DocumentFinding::query()->create(['document_scan_id' => $scan->id, 'schema_version' => '1.0',
            'observations' => ['document' => ['page_count' => 2],
                'entities' => [['id' => 'person', 'type' => 'person', 'role' => 'Applicant', 'display_name' => 'Amina Noor', 'related_entity_ids' => [], 'page_numbers' => [1, 2]]],
                'fields' => [
                    ['id' => 'name-1', 'entity_id' => 'person', 'evidence' => ['page_number' => 1], 'label' => 'Marital status'],
                    ['id' => 'name-2', 'entity_id' => 'person', 'evidence' => ['page_number' => 2], 'label' => 'Mobile'],
                ], 'tables' => [], 'content_blocks' => []],
            'record_ownership' => ['records' => [['id' => 'r1', 'anchor' => 'One applicant', 'regions' => [
                ['page_number' => 1, 'bbox' => null], ['page_number' => 2, 'bbox' => null],
            ]]], 'findings' => ['person' => 'r1', 'name-1' => 'r1', 'name-2' => 'r1']]]);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($scan) {
            $page = (int) str_replace('page ', '', base64_decode($request['input'][0]['data']));
            $value = $page === 1
                ? ['field_id' => 'customer.marital_status', 'value' => 'Married', 'evidence_ids' => ['name-1']]
                : ['field_id' => 'contacts[mobile].value', 'value' => '0123000000', 'evidence_ids' => ['name-2']];
            $response = ['contract_version' => '1.0', 'schema_hash' => $scan->profile_schema_hash, 'records' => [[
                'record_ref' => 'r1', 'values' => [$value + ['entity_ref' => 'person', 'row_ref' => null, 'state' => 'provided']], 'warnings' => [],
            ]], 'unresolved' => []];

            return Http::response(['status' => 'completed', 'output_text' => json_encode($response, JSON_THROW_ON_ERROR)]);
        });
        (new ProcessProfileExtraction($scan->id))->handle(app(GeminiDocumentReader::class), app(ProfileProjection::class));
        $profile = $scan->fresh()->extractedProfiles()->sole();
        $this->assertSame([1, 2], $profile->source_pages);
        $this->assertSame([], $profile->original_projection['warnings']);
        app(ReviewExtractedProfile::class)->markReviewed($profile, 0, $staff);
        $this->merge($staff, $profile->fresh(), $customer, 'fill_empty');

        $this->assertSame('Married', $customer->fresh()->marital_status);
        $this->assertSame('0123000000', $customer->fresh()->primary_mobile);
        $this->assertSame('Amina Noor', $customer->fresh()->full_legal_name);
    }

    public function test_separate_documents_fill_then_overwrite_one_customer(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create(['full_legal_name' => 'Amina Noor', 'race' => 'Malay', 'country_of_residence' => 'Malaysia', 'primary_mobile' => null]);
        $birth = $this->mergedProfile($staff, $customer, [$this->value('customer.marital_status', 'Married')], 'fill_empty');
        $this->assertSame('Married', $customer->fresh()->marital_status);
        $payslip = $this->mergedProfile($staff, $customer->fresh(), [$this->value('contacts[mobile].value', '0123000000')], 'fill_empty');
        $this->assertSame('0123000000', $customer->fresh()->primary_mobile);
        $this->mergedProfile($staff, $customer->fresh(), [$this->value('customer.marital_status', 'Single')], 'overwrite');
        $this->assertSame('Single', $customer->fresh()->marital_status);
        $this->assertSame('0123000000', $customer->fresh()->primary_mobile);
        $this->assertNotSame($birth->id, $payslip->id);
    }

    /** @param  list<array<string, mixed>>  $extra */
    private function mergedProfile(User $staff, Customer $customer, array $extra, string $strategy): ExtractedProfile
    {
        Queue::fake([ProcessProfileExtraction::class]);
        $scan = $this->imageScan();
        app(StoreDocumentFindings::class)->store($scan, DocumentFindingsFixtures::identity($scan->id));
        $values = array_merge([$this->value('customer.full_legal_name', $customer->full_legal_name)], $extra);
        $response = ['contract_version' => '1.0', 'schema_hash' => $scan->profile_schema_hash, 'records' => [[
            'record_ref' => 'r1', 'values' => $values, 'warnings' => [],
        ]], 'unresolved' => []];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['status' => 'completed', 'output_text' => json_encode($response, JSON_THROW_ON_ERROR)])]);
        (new ProcessProfileExtraction($scan->id))->handle(app(GeminiDocumentReader::class), app(ProfileProjection::class));
        $profile = $scan->fresh()->extractedProfiles()->sole();
        app(ReviewExtractedProfile::class)->markReviewed($profile, 0, $staff);
        $profile = $profile->fresh();
        $this->assertTrue(collect($profile->reviewed_projection['values'])->contains(
            fn (array $value): bool => $value['field_id'] === $extra[0]['field_id'] && $value['value'] === $extra[0]['value']
        ));
        $this->merge($staff, $profile, $customer, $strategy);

        return $profile;
    }

    private function merge(User $staff, $profile, Customer $customer, string $strategy): void
    {
        $preview = app(ProfileMergePreview::class)->make($profile, $customer->fresh(), $strategy, [], []);
        app(MergeExtractedProfile::class)->commit($profile, $customer, $staff, $strategy, (string) str()->uuid(), $preview['baseline'], [], []);
    }

    /** @return array<string, mixed> */
    private function value(string $field, mixed $value, ?string $row = null): array
    {
        return ['field_id' => $field, 'entity_ref' => 'person-1', 'row_ref' => $row, 'state' => 'provided', 'value' => $value, 'evidence_ids' => ['name']];
    }

    private function imageScan(): DocumentScan
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
}
