<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocumentScan;
use App\Livewire\Documents\Show;
use App\Models\Customer;
use App\Models\DocumentScan;
use App\Models\User;
use App\Services\ApplicationFormReference;
use App\Services\CustomerProfileSchema;
use App\Services\GeminiDocumentReader;
use App\Services\PdfPageBatches;
use App\Services\ReviewExtractedProfile;
use App\Services\SinglePassExtraction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class FragmentGroupingTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_form_reference_rejects_generic_version_and_nric_values(): void
    {
        $evidence = 'FORM-12345 BIMB-2025 v1.2 900101011234 APP-889912';
        $this->assertNull(ApplicationFormReference::usable('FORM-12345', null, $evidence));
        $this->assertNull(ApplicationFormReference::usable('BIMB-2025', null, $evidence));
        $this->assertNull(ApplicationFormReference::usable('v1.2', null, $evidence));
        $this->assertNull(ApplicationFormReference::usable('900101011234', '900101011234', $evidence));
        $this->assertNull(ApplicationFormReference::usable('APP-889912', null, 'no matching quote'));
        $this->assertSame('APP889912', ApplicationFormReference::usable('APP-889912', '900101011234', $evidence));
    }

    public function test_one_applicant_across_responses_groups_only_on_a_specific_form_reference(): void
    {
        [$scan] = $this->prepare(4, 2);
        $this->respond([
            $this->payload([$this->record('r1', [
                $this->value('customer.full_legal_name', 'Amina Noor', 'Amina Noor APP-889912', 1),
                $this->value('primary_identification.number', '900101011234', '900101011234', 1),
            ], 1, 'APP-889912', 'Application APP-889912')]),
            $this->payload([$this->record('r1', [
                $this->value('addresses[residential].line_1', '12 Jalan Ampang', '12 Jalan Ampang APP-889912', 3),
            ], 3, 'APP-889912', 'Application APP-889912')]),
        ]);

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $this->assertSame(2, $scan->extractedProfiles()->count());
        $this->assertEqualsCanonicalizing(
            ['12 Jalan Ampang', '900101011234', 'Amina Noor'],
            $scan->extractedProfiles()->get()->flatMap(fn ($profile) => collect($profile->original_projection['values'])->pluck('value'))->all(),
        );
        $this->assertCount(0, $this->held($scan));
        $this->assertSame('complete', $scan->fresh()->findings->observations['document']['reading_status']);
        $this->assertEvidence($scan->fresh());
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_missing_reference_holds_the_later_fragment_until_staff_attach_or_separate_it(): void
    {
        [$scan, $staff] = $this->prepare(4, 2);
        $customer = Customer::factory()->create();
        $before = $customer->fresh()->getAttributes();
        $this->respond([
            $this->payload([$this->record('r1', [$this->value('customer.full_legal_name', 'Amina Noor', 'Amina Noor', 1)], 1)]),
            $this->payload([$this->record('r9', [$this->value('customer.full_legal_name', 'Amina Noor', 'Amina Noor', 3)], 3)]),
        ]);
        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));
        $this->assertSame(2, $scan->extractedProfiles()->count());
        $this->assertCount(0, $this->held($scan));
        $this->assertSame($before, $customer->fresh()->getAttributes());
    }

    public function test_confirm_separate_and_reverse_keep_the_profile_and_its_audit_event(): void
    {
        [$scan, $staff] = $this->prepare(4, 2);
        $this->respond([
            $this->payload([$this->record('r1', [$this->value('customer.full_legal_name', 'Amina Noor', 'Amina Noor', 1)], 1)]),
            $this->payload([$this->record('r2', [$this->value('addresses[residential].line_1', '12 Jalan Ampang', '12 Jalan Ampang', 3)], 3)]),
        ]);
        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));
        $this->assertSame(2, $scan->extractedProfiles()->count());
        $this->assertCount(0, $this->held($scan));
        $this->actingAs($staff)->get(route('documents.show', $scan))
            ->assertOk()->assertSee('Applicant 1')->assertSee('Applicant 2')->assertSee('AMINA NOOR')->assertSee('12 JALAN AMPANG');
    }

    public function test_generic_references_and_same_person_without_a_serial_do_not_group(): void
    {
        [$scan] = $this->prepare(4, 2);
        $this->respond([
            $this->payload([$this->record('r1', [
                $this->value('customer.full_legal_name', 'Amina Noor', 'Amina Noor FORM-12345', 1),
                $this->value('primary_identification.number', '900101011234', '900101011234', 1),
            ], 1, 'FORM-12345', 'FORM-12345')]),
            $this->payload([$this->record('r1', [
                $this->value('addresses[residential].line_1', '12 Jalan Ampang', '12 Jalan Ampang BIMB-2025', 3),
            ], 3, 'BIMB-2025', 'BIMB-2025')]),
        ]);
        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $this->assertSame(2, $scan->extractedProfiles()->count());
        $this->assertTrue($scan->extractedProfiles->every(fn ($profile): bool => $profile->original_projection['form_reference'] === null));
        $this->assertCount(0, $this->held($scan));
    }

    public function test_two_applications_for_one_person_stay_separate_when_form_serials_differ(): void
    {
        [$scan, $staff] = $this->prepare(4, 2);
        $this->respond([
            $this->payload([$this->record('r1', [
                $this->value('customer.full_legal_name', 'Amina Noor', 'Amina Noor APP-111111', 1),
                $this->value('primary_identification.number', '900101011234', '900101011234', 1),
            ], 1, 'APP-111111', 'APP-111111')]),
            $this->payload([$this->record('r1', [
                $this->value('customer.full_legal_name', 'Amina Noor', 'Amina Noor APP-222222', 3),
                $this->value('primary_identification.number', '900101011234', '900101011234', 3),
            ], 3, 'APP-222222', 'APP-222222')]),
        ]);
        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $this->assertSame(2, $scan->extractedProfiles()->count());
        $this->assertCount(0, $this->held($scan));
        foreach ($scan->extractedProfiles as $profile) {
            app(ReviewExtractedProfile::class)->markReviewed($profile, 0, $staff);
            $this->assertSame('reviewed', $profile->fresh()->review_status);
        }
    }

    public function test_conflicting_identities_on_one_form_reference_are_not_combined(): void
    {
        [$scan, $staff] = $this->prepare(4, 2);
        $this->respond([
            $this->payload([$this->record('r1', [$this->value('customer.full_legal_name', 'Amina Noor', 'Amina Noor APP-889912', 1)], 1, 'APP-889912', 'APP-889912')]),
            $this->payload([$this->record('r2', [$this->value('customer.full_legal_name', 'Siti Amin', 'Siti Amin APP-889912', 3)], 3, 'APP-889912', 'APP-889912')]),
        ]);
        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));
        $this->assertSame(2, $scan->extractedProfiles()->count());
        $this->assertTrue($scan->extractedProfiles->every(fn ($profile): bool => count($profile->original_projection['values']) === 1));

        $ownership = $scan->fresh()->findings->record_ownership;
        $sitiId = null;
        foreach ($ownership['fragments'] as $index => $fragment) {
            if (($fragment['identity']['name'] ?? null) === 'siti amin') {
                $ownership['fragments'][$index]['state'] = 'held';
                $sitiId = $fragment['id'];
            }
        }
        $scan->fresh()->findings->update(['record_ownership' => $ownership]);
        $amina = $scan->extractedProfiles()->get()->first(fn ($profile) => $profile->original_projection['values'][0]['value'] === 'Amina Noor');
        $before = $amina->reviewed_projection;
        try {
            app(ReviewExtractedProfile::class)->attachFragment($amina, $sitiId, 'Wrong person', $staff);
            $this->fail('Conflicting names must not attach.');
        } catch (ValidationException) {
            $this->assertSame($before, $amina->fresh()->reviewed_projection);
        }
    }

    public function test_thirty_people_reuse_model_refs_without_sharing_a_profile(): void
    {
        [$scan] = $this->prepare(30, 8);
        $payloads = [];
        $person = 1;
        foreach ([1, 9, 17, 25] as $start) {
            $records = [];
            $end = min(30, $start + 7);
            for ($page = $start; $page <= $end; $page++) {
                $records[] = $this->record('r'.(($page - $start) + 1), [
                    $this->value('customer.full_legal_name', 'Person '.$person, 'Person '.$person, $page),
                ], $page);
                $person++;
            }
            $payloads[] = $this->payload($records);
        }
        $this->respond($payloads);
        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $this->assertSame(30, $scan->extractedProfiles()->count());
        $names = $scan->extractedProfiles()->get()->flatMap(fn ($profile) => array_column($profile->original_projection['values'], 'value'));
        $this->assertSame(30, $names->unique()->count());
        $this->assertCount(0, $this->held($scan));
    }

    public function test_fourteen_pages_with_one_serial_make_one_profile_and_without_it_hold_the_rest(): void
    {
        [$scan] = $this->prepare(14, 2);
        $payloads = [];
        for ($start = 1; $start <= 14; $start += 2) {
            $values = $start === 1
                ? [$this->value('customer.full_legal_name', 'Amina Noor', 'Amina Noor APP-889912', 1)]
                : [$this->value('addresses[residential].line_1', 'Line '.$start, 'Line '.$start.' APP-889912', $start)];
            $payloads[] = $this->payload([$this->record('r1', $values, $start, 'APP-889912', 'APP-889912')]);
        }
        $this->respond($payloads);
        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));
        $this->assertSame(7, $scan->extractedProfiles()->count());
        $this->assertCount(0, $this->held($scan));
        $this->assertSame('complete', $scan->fresh()->findings->observations['document']['reading_status']);

        [$again] = $this->prepare(14, 2);
        $payloads = [];
        for ($start = 1; $start <= 14; $start += 2) {
            $values = $start === 1
                ? [$this->value('customer.full_legal_name', 'Amina Noor', 'Amina Noor', 1)]
                : [$this->value('addresses[residential].line_1', 'Line '.$start, 'Line '.$start, $start)];
            $payloads[] = $this->payload([$this->record('r'.$start, $values, $start)]);
        }
        $this->respond($payloads);
        app(SinglePassExtraction::class)->run($again, app(GeminiDocumentReader::class));
        $this->assertSame(7, $again->extractedProfiles()->count());
        $this->assertCount(0, $this->held($again));
    }

    public function test_repeat_processing_cache_order_changes_and_reviewed_values_stay_stable(): void
    {
        [$scan] = $this->prepare(2, 2);
        $first = $this->record('r1', [$this->value('customer.full_legal_name', 'Amina Noor', 'Amina Noor', 1)], 1);
        $second = $this->record('r2', [$this->value('customer.full_legal_name', 'Siti Amin', 'Siti Amin', 1)], 1);
        $this->respond([$this->payload([$first, $second])]);
        $pass = app(SinglePassExtraction::class);
        $pass->run($scan, app(GeminiDocumentReader::class));
        $before = $scan->extractedProfiles()->orderBy('record_ref')->get()->map(fn ($profile) => $profile->original_projection)->all();
        $ids = collect($scan->fresh()->findings->record_ownership['fragments'])->pluck('id')->sort()->values()->all();

        $scan->update(['profile_status' => 'not_started']);
        $pass->run($scan->fresh(), app(GeminiDocumentReader::class));
        Http::assertSentCount(1);
        $this->assertSame(2, $scan->fresh()->extractedProfiles()->count());
        $this->assertEquals($before, $scan->fresh()->extractedProfiles()->orderBy('record_ref')->get()->map(fn ($profile) => $profile->original_projection)->all());

        $files = Storage::disk('local')->allFiles('document-scans/'.$scan->id.'/profile-batches');
        Storage::disk('local')->put($files[0], json_encode(['readable' => 'bad']));
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed', 'output_text' => json_encode($this->payload([$second, $first])),
        ])]);
        $scan->update(['profile_status' => 'not_started']);
        $pass->run($scan->fresh(), app(GeminiDocumentReader::class));
        $this->assertSame($ids, collect($scan->fresh()->findings->record_ownership['fragments'])->pluck('id')->sort()->values()->all());
        $this->assertSame(2, $scan->fresh()->extractedProfiles()->count());
    }

    public function test_a_reviewed_profile_is_not_changed_by_a_later_matching_fragment(): void
    {
        [$scan, $staff] = $this->prepare(2, 2);
        $this->respond([$this->payload([$this->record('r1', [$this->value('customer.full_legal_name', 'Amina Noor', 'Amina Noor APP-889912', 1)], 1, 'APP-889912', 'APP-889912')])]);
        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));
        $profile = $scan->extractedProfiles()->sole();
        $profile->update(['revision' => 1, 'review_status' => 'reviewed']);
        $profile->reviewEvents()->create(['actor_id' => $staff->id, 'revision' => 1, 'previous_value' => null, 'new_value' => ['status' => 'reviewed'], 'reason' => 'Reviewed', 'created_at' => now()]);
        $reviewed = $profile->fresh()->reviewed_projection;
        app()->instance(PdfPageBatches::class, new class extends PdfPageBatches
        {
            public function make(DocumentScan $scan, int $size): array
            {
                return ['count' => 4, 'batches' => [1 => '%PDF', 3 => '%PDF']];
            }
        });
        $this->respond([$this->payload([$this->record('r1', [$this->value('addresses[residential].line_1', '12 Jalan Ampang', '12 Jalan Ampang APP-889912', 3)], 3, 'APP-889912', 'APP-889912')])]);
        $scan->update(['profile_status' => 'not_started']);
        app(SinglePassExtraction::class)->run($scan->fresh(), app(GeminiDocumentReader::class));

        $this->assertSame($reviewed, $profile->fresh()->reviewed_projection);
        $this->assertSame(2, $scan->fresh()->extractedProfiles()->count());
        $this->assertCount(0, $this->held($scan));
    }

    public function test_partial_failure_and_a_503_retry_keep_completed_work(): void
    {
        [$scan] = $this->prepare(6, 2);
        $good = fn (int $page, string $name) => $this->payload([$this->record('r1', [
            $this->value('customer.full_legal_name', $name, $name, $page),
        ], $page)]);
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['status' => 'completed', 'output_text' => json_encode($good(1, 'Amina Noor'))])
            ->push(['status' => 'completed', 'output_text' => '{'])
            ->push(['status' => 'completed', 'output_text' => json_encode($good(5, 'Siti Amin'))]),
        ]);
        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));
        $this->assertSame('failed', $scan->fresh()->profile_status);
        $this->assertSame(['Amina Noor', 'Siti Amin'], $scan->extractedProfiles()->orderBy('record_ref')->get()->map(fn ($profile) => $profile->original_projection['values'][0]['value'])->all());
        $kept = $scan->extractedProfiles()->first()->original_projection;
        $block = $scan->fresh()->findings->observations['content_blocks'][0]['id'];

        $factory = new Factory;
        Http::swap($factory);
        $factory->preventStrayRequests();
        $factory->fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed', 'output_text' => json_encode($good(3, 'Farid Ali')),
        ])]);
        app(SinglePassExtraction::class)->run($scan->fresh(), app(GeminiDocumentReader::class));
        $factory->assertSentCount(1);
        $this->assertSame($kept, $scan->extractedProfiles()->where('record_ref', 'p0001')->sole()->original_projection);
        $this->assertSame($block, collect($scan->fresh()->findings->observations['content_blocks'])->firstWhere('id', $block)['id']);
        $this->assertSame(3, $scan->extractedProfiles()->count());

        [$retry] = $this->prepare(4, 2);
        $factory = new Factory;
        Http::swap($factory);
        $factory->preventStrayRequests();
        $factory->fake(['generativelanguage.googleapis.com/*' => $factory->sequence()
            ->push(['status' => 'completed', 'output_text' => json_encode($good(1, 'Amina Noor'))])
            ->push('busy', 503)
            ->push(['status' => 'completed', 'output_text' => json_encode($good(3, 'Siti Amin'))]),
        ]);
        try {
            (new ProcessDocumentScan($retry->id))->handle(app(GeminiDocumentReader::class));
            $this->fail('A 503 must retry before profiles are stored.');
        } catch (RuntimeException $exception) {
            $this->assertSame(503, $exception->getCode());
        }
        $this->assertSame(0, $retry->extractedProfiles()->count());
        $this->assertNull($retry->fresh()->findings);
        (new ProcessDocumentScan($retry->id))->handle(app(GeminiDocumentReader::class));
        $this->assertSame(2, $retry->fresh()->extractedProfiles()->count());
        $this->assertEvidence($retry->fresh());
        $factory->assertSentCount(3);
    }

    public function test_local_pages_shift_to_absolute_pages_and_mixed_pages_are_held(): void
    {
        [$scan] = $this->prepare(16, 8);
        $this->respond([
            $this->payload([$this->record('r1', [$this->value('customer.full_legal_name', 'Amina Noor', 'Amina Noor', 1)], 1)]),
            $this->payload([$this->record('r1', [$this->value('customer.full_legal_name', 'Farid Ali', 'Farid Ali', 1)], 1)]),
        ]);
        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));
        $farid = $scan->extractedProfiles()->get()->first(fn ($profile) => $profile->original_projection['values'][0]['value'] === 'Farid Ali');
        $this->assertSame([9], $farid->source_pages);

        [$mixed] = $this->prepare(16, 8);
        $this->respond([
            $this->payload([$this->record('r1', [$this->value('customer.full_legal_name', 'Amina Noor', 'Amina Noor', 1)], 1)]),
            $this->payload([$this->record('r1', [
                $this->value('customer.full_legal_name', 'Siti Amin', 'Siti Amin', 1),
                $this->value('addresses[residential].line_1', '12 Jalan Ampang', '12 Jalan Ampang', 9),
            ], 1)]),
        ]);
        app(SinglePassExtraction::class)->run($mixed, app(GeminiDocumentReader::class));
        $address = $mixed->extractedProfiles()->get()->first(fn ($profile) => collect($profile->original_projection['values'])->contains('value', '12 Jalan Ampang'));
        $this->assertNotNull($address);
        $this->assertSame([9], $address->source_pages);
        $this->assertCount(0, $this->held($mixed));
    }

    public function test_roles_use_exact_aliases(): void
    {
        [$scan] = $this->prepare(1, 2);
        $this->respond([$this->payload([$this->record('r1', [
            $this->value('customer.full_legal_name', 'Employer Name', 'Employer Name', 1, 'employer'),
            $this->value('customer.full_legal_name', 'Child Name', 'Child Name', 1, 'child'),
            $this->value('relationships[].related_person.full_legal_name', 'Spouse Name', "applicant's spouse", 1, "applicant's spouse", 'kin-1'),
            $this->value('relationships[].relationship', 'spouse', 'Witness', 1, 'witness', 'kin-2'),
            $this->value('customer.full_legal_name', 'Amina Noor', 'Amina Noor', 1),
        ], 1)])]);
        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));
        $values = collect($scan->extractedProfiles()->sole()->original_projection['values'])->pluck('value', 'field_id');
        $this->assertSame('Amina Noor', $values['customer.full_legal_name']);
        $this->assertSame('Spouse Name', $values['relationships[].related_person.full_legal_name']);
        $this->assertFalse($values->has('relationships[].relationship'));
    }

    public function test_historical_findings_are_left_unchanged(): void
    {
        [$scan] = $this->prepare(1, 2);
        $observations = ['fields' => [['id' => 'legacy']], 'sections' => [], 'tables' => []];
        $scan->findings()->create(['schema_version' => '1.0', 'observations' => $observations, 'record_ownership' => ['records' => [], 'findings' => []]]);
        Http::preventStrayRequests();
        Http::fake();
        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));
        Http::assertSentCount(0);
        $this->assertSame($observations, $scan->fresh()->findings->observations);
        $this->assertSame(0, $scan->extractedProfiles()->count());
        $this->assertSame('completed', $scan->fresh()->profile_status);
    }

    public function test_staff_authorization_and_merged_profiles(): void
    {
        [$scan, $staff] = $this->prepare(4, 2);
        $inactive = User::factory()->inactive()->create();
        $this->respond([
            $this->payload([$this->record('r1', [$this->value('customer.full_legal_name', 'Amina Noor', 'Amina Noor', 1)], 1)]),
            $this->payload([$this->record('r2', [$this->value('addresses[residential].line_1', '12 Jalan Ampang', '12 Jalan Ampang', 3)], 3)]),
        ]);
        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));
        $this->actingAs($inactive)->get(route('documents.show', $scan))->assertRedirect();
        $this->assertSame(2, $scan->extractedProfiles()->count());
        $this->assertCount(0, $this->held($scan));
        $this->actingAs($staff)->get(route('documents.show', $scan))
            ->assertOk()->assertSee('12 JALAN AMPANG')->assertSee('Applicant 1');
        Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])->assertSee('12 Jalan Ampang');
    }

    /** @return array{0: DocumentScan, 1: User} */
    private function prepare(int $pages, int $size): array
    {
        Storage::fake('local');
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite');
        config()->set('document_scanner.pages_per_batch', $size);
        $staff = User::factory()->staff()->create();
        $snapshot = CustomerProfileSchema::snapshot();
        $scan = DocumentScan::factory()->for($staff, 'uploader')->create([
            'profile_schema_hash' => $snapshot->hash,
        ]);
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

        return [$scan, $staff];
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

    /** @param list<array<string, mixed>> $values @return array<string, mixed> */
    private function record(string $ref, array $values, int $page, ?string $form = null, ?string $formEvidence = null): array
    {
        return [
            'record_ref' => $ref, 'anchor' => 'Application', 'source_pages' => [$page], 'warnings' => [],
            'form_reference' => $form, 'form_reference_evidence' => $formEvidence, 'values' => $values,
        ];
    }

    /** @return array<string, mixed> */
    private function value(string $field, string $value, string $quote, int $page, string $role = 'Applicant', string $entity = 'person-1'): array
    {
        return [
            'field_id' => $field, 'entity_ref' => $entity, 'entity_role' => $role, 'row_ref' => str_contains($field, '[]') ? 'row-1' : null,
            'state' => 'provided', 'value' => $value, 'source_page' => $page, 'evidence_text' => $quote,
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function held(DocumentScan $scan)
    {
        return collect($scan->fresh()->findings->record_ownership['fragments'] ?? [])->where('state', 'held')->values();
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
