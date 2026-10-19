<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocumentScan;
use App\Models\Customer;
use App\Models\DocumentScan;
use App\Models\User;
use App\Services\CustomerProfileSchema;
use App\Services\GeminiDocumentReader;
use App\Services\PdfPageBatches;
use App\Services\SinglePassExtraction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use UnexpectedValueException;

class SinglePassExtractionTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_profile_is_extracted_without_customer_writes_or_a_second_call(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $before = $customer->fresh()->getAttributes();
        $scan = $this->scan($staff);
        $this->fake($this->payload([[
            'record_ref' => 'r1', 'anchor' => 'Application', 'source_pages' => [1], 'warnings' => [],
            'bbox' => ['x' => 116, 'y' => 91, 'width' => 866, 'height' => 738],
            'values' => [$this->value('customer.full_legal_name', 'provided', 'Amina Noor', 'Name: Amina Noor')],
        ]]));

        (new ProcessDocumentScan($scan->id))->handle(app(GeminiDocumentReader::class));

        $scan->refresh();
        $profile = $scan->extractedProfiles()->sole();
        $this->assertSame('completed', $scan->extraction_status);
        $this->assertSame('completed', $scan->profile_status);
        $this->assertNull($scan->record_plan[0]['regions'][0]['bbox']);
        $this->assertSame('Amina Noor', $profile->original_projection['values'][0]['value']);
        $this->assertSame('Name: Amina Noor', $scan->findings->observations['content_blocks'][0]['evidence']['text_snippet']);
        $this->assertSame($before, $customer->fresh()->getAttributes());
        $this->assertDatabaseCount('customers', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request['input'][1]['text'], CustomerProfileSchema::hash())
            && str_contains($request['input'][1]['text'], 'contract_version must be the exact string 2.0')
            && str_contains($request['input'][1]['text'], 'A value is one short line: only the text printed for that field, then stop.')
            && str_contains($request['input'][1]['text'], 'When a word is written as single letters with spaces between them, join those letters into the word and return that one short line. E S E Y is ESEY.')
            && str_contains($request['input'][1]['text'], 'Do not stop after bank name and account number if branch is printed')
            && str_contains($request['input'][1]['text'], 'bank_accounts[].branch_address')
            && str_contains($request['input'][1]['text'], 'Fields to fill:')
            && str_contains($request['input'][1]['text'], 'customer.full_legal_name | Full Legal Name | string | Applicant\'s full legal name'));

        $this->actingAs($staff)->get(route('documents.show', $scan))
            ->assertOk()
            ->assertSeeInOrder(['Applicant 1', 'Raw AI response', 'Copy'])
            ->assertSee('"record_ref": "r1"')
            ->assertSee('Applicant 1')
            ->assertSee('Personal details')
            ->assertSee('Full Legal Name')
            ->assertSee('Fields left blank')
            ->assertSee('AMINA NOOR')
            ->assertSee('Match')
            ->assertDontSee('Review this applicant')
            ->assertDontSee('Attach to selected applicant')
            ->assertDontSee('0 findings stored.');
    }

    public function test_a_name_that_contains_the_models_reasoning_is_not_stored(): void
    {
        $scan = $this->scan();
        $this->fake($this->payload([[
            'record_ref' => 'applicant_1', 'anchor' => 'Application', 'source_pages' => [1], 'warnings' => [],
            'values' => [
                $this->value('customer.full_legal_name', 'provided', "Abdul Rahman bin YusoƯ\n\nNote: OCR misread this. I will use Yusoff."),
                $this->value('primary_identification.number', 'provided', '931023-11-5077'),
            ],
        ]]));

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $profile = $scan->extractedProfiles()->sole();
        $values = collect($profile->original_projection['values'])->pluck('value', 'field_id');
        $this->assertFalse($values->has('customer.full_legal_name'));
        $this->assertSame('931023115077', preg_replace('/\D/', '', (string) $values['primary_identification.number']));
        $this->assertContains('Invalid field value', array_column($profile->original_projection['warnings'], 'issue'));
    }

    public function test_a_record_with_only_invalid_values_does_not_create_an_empty_applicant(): void
    {
        $scan = $this->scan();
        $this->fake($this->payload([[
            'record_ref' => 'MAZRUL KHAZANI BIN MAHMUD', 'anchor' => 'Application', 'source_pages' => [1], 'warnings' => [],
            'values' => [
                $this->value('customer.full_legal_name', 'provided', "MAZRUL KHAZANI BIN MAHMUD\n\nWait: starting new records."),
            ],
        ], [
            'record_ref' => 'MAZRUL KHAZANI BIN MAHMUD', 'anchor' => 'Application', 'source_pages' => [1], 'warnings' => [],
            'values' => [
                $this->value('customer.full_legal_name', 'provided', 'MAZRUL KHAZANI BIN MAHMUD'),
                $this->value('primary_identification.number', 'provided', '820222-03-5625'),
            ],
        ]]));

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $profile = $scan->extractedProfiles()->sole();
        $values = collect($profile->original_projection['values'])->pluck('value', 'field_id');
        $this->assertSame('MAZRUL KHAZANI BIN MAHMUD', $values['customer.full_legal_name']);
        $this->assertSame('820222035625', preg_replace('/\D/', '', (string) $values['primary_identification.number']));
        $this->assertSame(1, $scan->extractedProfiles()->count());
    }

    public function test_a_broken_ff_ligature_is_read_as_ff(): void
    {
        $staff = User::factory()->staff()->create();
        $scan = $this->scan($staff);
        $this->fake($this->payload([[
            'record_ref' => 'applicant_1', 'anchor' => 'Application', 'source_pages' => [1], 'warnings' => [],
            'values' => [
                $this->value('customer.full_legal_name', 'provided', "Abdul Rahman bin Yuso\u{01AF}"),
                $this->value('contacts[email].value', 'provided', "abdulrahmanyuso\u{01AF}8@gmail.com"),
            ],
        ]]));

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $values = collect($scan->extractedProfiles()->sole()->original_projection['values'])->pluck('value', 'field_id');
        $this->assertSame('Abdul Rahman bin Yusoff', $values['customer.full_legal_name']);
        $this->assertSame('abdulrahmanyusoff8@gmail.com', $values['contacts[email].value']);
        $this->actingAs($staff)->get(route('documents.show', $scan))
            ->assertOk()
            ->assertSee('ABDUL RAHMAN BIN YUSOFF')
            ->assertSee('abdulrahmanyusoff8@gmail.com');
    }

    public function test_a_name_used_as_the_record_reference_is_still_shown(): void
    {
        $staff = User::factory()->staff()->create();
        $scan = $this->scan($staff);
        $this->fake($this->payload([[
            'record_ref' => 'Zanariah binti Mat Nor', 'anchor' => 'Application', 'source_pages' => [1], 'warnings' => [],
            'values' => [$this->value('customer.full_legal_name', 'provided', 'ZANARIAH BINTI MAT NOR')],
        ]]));

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $this->actingAs($staff)->get(route('documents.show', $scan))
            ->assertOk()
            ->assertSee('ZANARIAH BINTI MAT NOR')
            ->assertDontSee('Unnamed applicant');
    }

    public function test_many_applicants_and_repeated_names_stay_separate(): void
    {
        $scan = $this->scan();
        $records = [];
        for ($index = 1; $index <= 30; $index++) {
            $records[] = [
                'record_ref' => 'a'.$index, 'anchor' => 'Applicant '.$index, 'source_pages' => [1], 'warnings' => [],
                'values' => [$this->value('customer.full_legal_name', 'provided', $index === 2 ? 'Amina Noor' : 'Person '.$index)],
            ];
        }
        $records[0]['values'][0]['value'] = 'Amina Noor';
        $this->fake($this->payload($records));

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $this->assertSame(30, $scan->extractedProfiles()->count());
        $this->assertSame(2, $scan->extractedProfiles()->get()->filter(
            fn ($profile): bool => $profile->original_projection['values'][0]['value'] === 'Amina Noor'
        )->count());
        Http::assertSentCount(1);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_blank_ticks_explicit_no_and_related_people_follow_the_profile_states(): void
    {
        $scan = $this->scan();
        $this->fake($this->payload([[
            'record_ref' => 'r1', 'anchor' => 'Application', 'source_pages' => [1], 'warnings' => [],
            'values' => [
                $this->value('customer.full_legal_name', 'provided', 'Amina Noor', 'Amina Noor'),
                $this->value('customer.full_legal_name', 'provided', 'Other Name', 'Other Name'),
                $this->value('customer.employment_status', 'unanswered', null),
                $this->value('bank_accounts[].account_type', 'not_provided', null, 'Account type', 'acct-1'),
                $this->value('fatca.us_person_for_tax', 'explicit_negative', null, 'No'),
                $this->value('customer.not_a_field', 'provided', 'nope'),
                $this->value('relationships[].relationship', 'ambiguous', 'spouse', 'Relationship unclear', 'kin-1'),
                $this->value('relationships[].related_person.full_legal_name', 'provided', 'Siti Amin', 'Siti Amin', 'kin-1', 'spouse-1', 'Spouse'),
                $this->value('relationships[].related_person.full_legal_name', 'provided', 'Other Kin', 'Other Kin', 'kin-1', 'spouse-2', 'Spouse'),
            ],
        ], 'not-a-record']));

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $profile = $scan->extractedProfiles()->sole();
        $values = collect($profile->original_projection['values'])->keyBy('field_id');
        $this->assertSame('Amina Noor', $values['customer.full_legal_name']['value']);
        $this->assertFalse($values->has('customer.employment_status'));
        $this->assertFalse($values->has('bank_accounts[].account_type'));
        $this->assertSame('provided', $values['fatca.us_person_for_tax']['state']);
        $this->assertSame('no', $values['fatca.us_person_for_tax']['value']);
        $this->assertSame('ambiguous', $values['relationships[].relationship']['state']);
        $this->assertNull($values['relationships[].relationship']['value']);
        $this->assertSame('Siti Amin', $values['relationships[].related_person.full_legal_name']['value']);
        $issues = array_column($profile->original_projection['warnings'], 'issue');
        $this->assertContains('Conflicting value requires review', $issues);
        $this->assertContains('Unsupported field', $issues);
        $this->assertContains('Child row refers to different people', $issues);
        $this->assertSame('A structurally invalid profile record was rejected.', $scan->fresh()->profile_unresolved[0]['warning']);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_a_blank_page_is_skipped_and_pages_with_content_are_kept(): void
    {
        config()->set('document_scanner.pages_per_batch', 2);
        $staff = User::factory()->staff()->create();
        $scan = $this->scan($staff);
        app()->instance(PdfPageBatches::class, new class extends PdfPageBatches
        {
            public function make($scan, int $size): array
            {
                return ['count' => 3, 'batches' => [1 => '%PDF-1', 3 => '%PDF-3']];
            }
        });
        $blank = [
            'contract_version' => '2.0', 'schema_hash' => CustomerProfileSchema::hash(),
            'document_type' => null, 'title' => null, 'summary' => '',
            'readable' => false, 'limitations' => 'The provided source material is blank.',
            'records' => [], 'unresolved' => [],
        ];
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['status' => 'completed', 'output_text' => json_encode($this->payload([[
                'record_ref' => 'r1', 'anchor' => 'Application', 'source_pages' => [1], 'warnings' => [],
                'values' => [$this->value('customer.full_legal_name', 'provided', 'Amina Noor', 'Amina Noor')],
            ]]))])
            ->push(['status' => 'completed', 'output_text' => json_encode($blank)]),
        ]);

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $scan->refresh();
        $this->assertSame('completed', $scan->profile_status);
        $this->assertNull($scan->profile_error);
        $this->assertSame('Amina Noor', $scan->extractedProfiles()->sole()->original_projection['values'][0]['value']);
        Http::assertSent(fn ($request): bool => str_contains($request['input'][1]['text'], 'A blank page is still readable.')
            && str_contains($request['input'][1]['text'], 'relationships[].related_person.mobile')
            && str_contains($request['input'][1]['text'], 'Use spouse only when the applicant and the related person are different genders.'));
        $this->actingAs($staff)->get(route('documents.show', $scan))
            ->assertOk()
            ->assertDontSee('A later page group was not readable');
    }

    public function test_an_incomplete_page_pair_is_read_one_page_at_a_time(): void
    {
        config()->set('document_scanner.pages_per_batch', 2);
        $scan = $this->scan();
        app()->instance(PdfPageBatches::class, new class extends PdfPageBatches
        {
            public function make($scan, int $size): array
            {
                if ($size === 1) {
                    return ['count' => 2, 'batches' => [1 => '%PDF-1', 2 => '%PDF-2']];
                }

                return ['count' => 2, 'batches' => [1 => '%PDF-pair']];
            }
        });
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['status' => 'incomplete'])
            ->push(['status' => 'completed', 'output_text' => json_encode($this->payload([[
                'record_ref' => 'r1', 'source_pages' => [1], 'warnings' => [],
                'values' => [
                    $this->value('customer.full_legal_name', 'provided', 'Amina Noor', 'Amina Noor'),
                    $this->value('customer.date_of_birth', 'provided', '25 03 1985', '25 03 1985'),
                    $this->value('employments[].employer_name', 'provided', 'ESEY', 'ESEY'),
                ],
            ]]))])
            ->push(['status' => 'completed', 'output_text' => json_encode($this->payload([[
                'record_ref' => 'r2', 'source_pages' => [1], 'warnings' => [],
                'values' => [
                    $this->value('bank_accounts[].bank_name', 'provided', 'CIMB', 'CIMB'),
                ],
            ]]))]),
        ]);

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $values = collect($scan->extractedProfiles()->sole()->original_projection['values'])->keyBy('field_id');
        $this->assertSame('completed', $scan->fresh()->profile_status);
        $this->assertSame('Amina Noor', $values['customer.full_legal_name']['value']);
        $this->assertSame('1985-03-25', $values['customer.date_of_birth']['value']);
        $this->assertSame('ESEY', $values['employments[].employer_name']['value']);
        $this->assertSame('CIMB', $values['bank_accounts[].bank_name']['value']);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => str_contains($request['input'][1]['text'], 'absolute pages 1-2')
            && str_contains($request['input'][1]['text'], 'fill only the fields printed on these pages'));
        Http::assertSent(fn ($request): bool => str_contains($request['input'][1]['text'], 'absolute pages 1-1'));
        Http::assertSent(fn ($request): bool => str_contains($request['input'][1]['text'], 'absolute pages 2-2'));
    }

    public function test_a_numbered_repeatable_field_is_read_as_the_listed_field(): void
    {
        $staff = User::factory()->staff()->create();
        $scan = $this->scan($staff);
        $this->fake($this->payload([[
            'record_ref' => 'applicant_1', 'anchor' => 'Application', 'source_pages' => [1], 'warnings' => [],
            'values' => [
                ['field_id' => 'relationships[0].relationship', 'value' => 'spouse'],
                ['field_id' => 'relationships[0].related_person.full_legal_name', 'value' => 'Suhaiza Binti Yaakub'],
                ['field_id' => 'employments[0].occupation', 'value' => 'Software Architect'],
                ['field_id' => 'bank_accounts[0].bank_name', 'value' => 'CIMB'],
            ],
        ]]));

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $values = collect($scan->extractedProfiles()->sole()->original_projection['values'])->keyBy('field_id');
        $this->assertSame('spouse', $values['relationships[].relationship']['value']);
        $this->assertSame('0', $values['relationships[].relationship']['row_ref']);
        $this->assertSame('Suhaiza Binti Yaakub', $values['relationships[].related_person.full_legal_name']['value']);
        $this->assertSame('Software Architect', $values['employments[].occupation']['value']);
        $this->assertSame('CIMB', $values['bank_accounts[].bank_name']['value']);
        Http::assertSent(fn ($request): bool => str_contains($request['input'][1]['text'], 'Do not write [0] or [1] inside a field id.'));
        $this->actingAs($staff)->get(route('documents.show', $scan))
            ->assertOk()
            ->assertSeeInOrder(['Family and next of kin', 'Relationship To Applicant', 'SPOUSE', 'Family Member Full Legal Name', 'SUHAIZA BINTI YAAKUB'])
            ->assertSeeInOrder(['Employment', 'Occupation', 'SOFTWARE ARCHITECT'])
            ->assertSeeInOrder(['Bank accounts', 'Bank Name', 'CIMB'])
            ->assertDontSee('relationships[0].relationship</dt>', false);
    }

    public function test_reused_record_refs_do_not_merge_across_responses_and_then_use_cache(): void
    {
        config()->set('document_scanner.pages_per_batch', 2);
        $scan = $this->scan();
        app()->instance(PdfPageBatches::class, new class extends PdfPageBatches
        {
            public function make($scan, int $size): array
            {
                return ['count' => 4, 'batches' => [1 => '%PDF-1', 3 => '%PDF-3']];
            }
        });
        $first = $this->payload([[
            'record_ref' => 'r1', 'anchor' => 'Application', 'source_pages' => [1], 'warnings' => [],
            'values' => [$this->value('customer.full_legal_name', 'provided', 'Amina Noor', 'Amina Noor')],
        ]]);
        $second = $this->payload([[
            'record_ref' => 'r1', 'anchor' => 'Application', 'source_pages' => [3], 'warnings' => [],
            'values' => [$this->value('customer.full_legal_name', 'provided', 'Other Person', 'Other Person', null, 'person-1', 'Applicant', 3)],
        ], [
            'record_ref' => 'r2', 'anchor' => 'Second application', 'source_pages' => [3], 'warnings' => [],
            'values' => [$this->value('customer.full_legal_name', 'provided', 'Amina Noor', 'Amina Noor', null, 'person-2', 'Applicant', 3)],
        ]]);
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['status' => 'completed', 'output_text' => json_encode($first)])
            ->push(['status' => 'completed', 'output_text' => json_encode($second)]),
        ]);

        $pass = app(SinglePassExtraction::class);
        $pass->run($scan, app(GeminiDocumentReader::class));

        $profiles = $scan->extractedProfiles()->orderBy('record_ref')->get();
        $this->assertSame(['p0001', 'p0002', 'p0003'], $profiles->pluck('record_ref')->all());
        $this->assertEqualsCanonicalizing(
            ['Amina Noor', 'Amina Noor', 'Other Person'],
            $profiles->map(fn ($profile) => $profile->original_projection['values'][0]['value'])->all(),
        );
        $this->assertTrue($profiles->every(fn ($profile): bool => count($profile->original_projection['values']) === 1));
        $this->assertCount(0, collect($scan->fresh()->findings->record_ownership['fragments'])->where('state', 'held'));
        Http::assertSentCount(2);

        $scan->update(['profile_status' => 'not_started']);
        $scan->extractedProfiles()->delete();
        $scan->findings()->delete();
        $pass->run($scan->fresh(), app(GeminiDocumentReader::class));

        Http::assertSentCount(2);
        $this->assertSame(3, $scan->fresh()->extractedProfiles()->count());
        $this->assertCount(0, collect($scan->fresh()->findings->record_ownership['fragments'])->where('state', 'held'));
    }

    public function test_a_later_page_group_is_told_who_was_already_found(): void
    {
        config()->set('document_scanner.pages_per_batch', 2);
        $scan = $this->scan();
        app()->instance(PdfPageBatches::class, new class extends PdfPageBatches
        {
            public function make($scan, int $size): array
            {
                return ['count' => 4, 'batches' => [1 => '%PDF-1', 3 => '%PDF-3']];
            }
        });
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['status' => 'completed', 'output_text' => json_encode($this->payload([[
                'record_ref' => 'r1', 'source_pages' => [1], 'warnings' => [],
                'values' => [
                    $this->value('customer.full_legal_name', 'provided', 'MOHD NORHISYAM BIN ZAKARIA', 'MOHD NORHISYAM BIN ZAKARIA'),
                    $this->value('primary_identification.number', 'provided', '850325-03-5545', '850325-03-5545'),
                ],
            ]]))])
            ->push(['status' => 'completed', 'output_text' => json_encode($this->payload([[
                'record_ref' => 'r2', 'source_pages' => [3], 'warnings' => [],
                'values' => [$this->value('employments[].employer_name', 'provided', 'ESEY ALUMINIUM GLASS', 'ESEY', '1', 'person-1', 'Applicant', 3)],
            ]]))]),
        ]);

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $sent = Http::recorded();
        $first = $sent[0][0]['input'][1]['text'];
        $second = $sent[1][0]['input'][1]['text'];
        $this->assertStringNotContainsString('Applicants already found', $first);
        $this->assertStringNotContainsString('MOHD NORHISYAM BIN ZAKARIA', $first);
        $this->assertStringContainsString('Applicants already found on earlier pages: MOHD NORHISYAM BIN ZAKARIA, IC 850325035545.', $second);
        $this->assertStringContainsString('Do not repeat a name or identity number already listed.', $second);
        $this->assertStringContainsString('A next-of-kin or family address is not an employer.', $second);
        $this->assertStringContainsString('Start a new record only when these pages show a different person.', $second);
    }

    public function test_a_monthly_income_sent_as_a_number_is_stored(): void
    {
        $scan = $this->scan();
        $this->fake($this->payload([[
            'record_ref' => 'r1', 'source_pages' => [1], 'warnings' => [],
            'values' => [$this->value('financial.monthly_income_rm', 'provided', 7000, '7000')],
        ]]));

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $this->assertSame('7000', $scan->extractedProfiles()->sole()->original_projection['values'][0]['value']);
    }

    /** @param list<array<string, mixed>|string> $records @return array<string, mixed> */
    private function payload(array $records): array
    {
        return [
            'contract_version' => '2.0', 'schema_hash' => CustomerProfileSchema::hash(),
            'document_type' => 'Application', 'title' => 'Form', 'summary' => 'One application.',
            'readable' => true, 'limitations' => null, 'records' => $records, 'unresolved' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function value(string $field, string $state, mixed $value, string $quote = 'Quoted', ?string $row = null, string $entity = 'person-1', string $role = 'Applicant', int $page = 1): array
    {
        return [
            'field_id' => $field, 'entity_ref' => $entity, 'entity_role' => $role, 'row_ref' => $row,
            'state' => $state, 'value' => $value, 'source_page' => $page, 'evidence_text' => $quote,
        ];
    }

    public function test_a_short_field_list_is_shown_without_evidence_metadata(): void
    {
        $staff = User::factory()->staff()->create();
        $scan = $this->scan($staff);
        $this->fake($this->payload([[
            'record_ref' => 'applicant_01',
            'source_pages' => [1],
            'values' => [
                ['field_id' => 'customer.full_legal_name', 'value' => 'Muhammad Aliff Bin Abdul Malek'],
                ['field_id' => 'primary_identification.number', 'value' => '970905-10-6721'],
                ['field_id' => 'primary_identification.type', 'value' => 'nric'],
                ['field_id' => 'addresses[residential].line_1', 'value' => 'Lot 12 Jalan Temerloh'],
                ['field_id' => 'addresses[residential].state', 'value' => 'Pahang'],
                ['field_id' => 'contacts[email].value', 'value' => 'alifmalek31@gmail.com'],
                ['field_id' => 'customer.employment_status', 'value' => 'self_employed'],
                ['field_id' => 'relationships[].relationship', 'value' => 'spouse'],
                ['field_id' => 'relationships[].related_person.full_legal_name', 'value' => 'NUR HAYANI ALYA BINTI AZHAR'],
                ['field_id' => 'relationships[].related_person.identification_number', 'value' => '990301-06-5314'],
                ['field_id' => 'bank_accounts[].bank_name', 'value' => 'Maybank'],
            ],
        ]]));

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $values = collect($scan->extractedProfiles()->sole()->original_projection['values'])->pluck('value', 'field_id');
        $this->assertSame('Muhammad Aliff Bin Abdul Malek', $values['customer.full_legal_name']);
        $this->assertSame('spouse', $values['relationships[].relationship']);
        $this->actingAs($staff)->get(route('documents.show', $scan))
            ->assertOk()
            ->assertSee('Applicant 1')
            ->assertSee('MUHAMMAD ALIFF BIN ABDUL MALEK')
            ->assertSee('1997-09-05')
            ->assertSee('MALE')
            ->assertSee('MALAYSIAN')
            ->assertSee('ENCIK')
            ->assertSee('From IC')
            ->assertSeeInOrder(['Race', 'MALAY', 'Default'])
            ->assertSeeInOrder(['Country Of Residence', 'MALAYSIA', 'Default'])
            ->assertSeeInOrder(['Issuing Country', 'MY', 'Default'])
            ->assertSeeInOrder(['Mailing Line 1', 'LOT 12 JALAN TEMERLOH', 'Default'])
            ->assertSeeInOrder(['Mailing State', 'PAHANG', 'Default'])
            ->assertSeeInOrder(['Residential Country', 'MALAYSIA', 'Default'])
            ->assertSeeInOrder(['Mailing Country', 'MALAYSIA', 'Default'])
            ->assertSeeInOrder(['Marital Status', 'MARRIED', 'Default'])
            ->assertSee('1999-03-01')
            ->assertSee('FEMALE')
            ->assertSeeInOrder(['Identification Type', 'NRIC', 'From IC'])
            ->assertSeeInOrder(['Employer Country', 'MALAYSIA', 'Default'])
            ->assertSee('Email')
            ->assertSee('alifmalek31@gmail.com')
            ->assertSeeInOrder(['Employment Status', 'SELF EMPLOYED'])
            ->assertSeeInOrder(['Account Holder Name', 'MUHAMMAD ALIFF BIN ABDUL MALEK', 'Default'])
            ->assertSeeInOrder(['Bank Country', 'MALAYSIA', 'Default'])
            ->assertSeeInOrder(['Residential Line 1', 'Residential State', 'Mailing Address Same As Residential', 'Mailing Line 1'])
            ->assertSeeInOrder(['Account Holder Name', 'Primary Bank Account', 'YES', 'Default', 'Bank Account Active', 'YES', 'Default'])
            ->assertSeeInOrder(['PEP Declaration Answer', 'NO', 'Default'])
            ->assertSee('Bankruptcy Declaration Date')
            ->assertSee('Bankruptcy Petition Remarks')
            ->assertSee('PEP Associate Occupation')
            ->assertSeeInOrder(['Investment Objectives', 'CAPITAL GROWTH', 'Default'])
            ->assertSeeInOrder(['Investment Risk Tolerance', 'MODERATE', 'Default'])
            ->assertSeeInOrder(['Investment Trading Knowledge', 'INTERMEDIATE', 'Default'])
            ->assertSeeInOrder(['Investment Products', 'LOCAL EQUITIES TRADING', 'Default'])
            ->assertSee('Other Brokerage Answer')
            ->assertSee('Other Brokerage Declaration Date')
            ->assertSee('Other Brokerage Account Type')
            ->assertSee('Other Brokerage Name')
            ->assertSee('Monthly Income (RM)')
            ->assertSee('Personal Annual Income')
            ->assertSee('Joint Annual Income')
            ->assertSee('Net Assets')
            ->assertSee('Portfolio Value')
            ->assertSee('Who Owns The Portfolio')
            ->assertSee('Sources Of Wealth')
            ->assertSee('Financial Declaration Date')
            ->assertSee('Family Member Employer Country')
            ->assertSee('Employer Registration Number')
            ->assertSee('Sole Or Joint Account')
            ->assertSee('Foreign Tax Number')
            ->assertSee('FATCA Declaration Date')
            ->assertSee('Malaysian Tax Number')
            ->assertSee('Family and next of kin')
            ->assertSee('NUR HAYANI ALYA BINTI AZHAR')
            ->assertSeeInOrder(['Relationship To Applicant', 'SPOUSE'])
            ->assertSee('Fields left blank')
            ->assertSee('Personal details')
            ->assertDontSee('No listed field was filled')
            ->assertDontSee('0 findings stored.');
    }

    public function test_a_male_applicant_and_male_relative_are_next_of_kin_instead_of_spouse(): void
    {
        $staff = User::factory()->staff()->create();
        $scan = $this->scan($staff);
        $this->fake($this->payload([[
            'record_ref' => 'applicant_01',
            'source_pages' => [1],
            'values' => [
                ['field_id' => 'customer.full_legal_name', 'value' => 'Ahmad Bin Ali'],
                ['field_id' => 'primary_identification.number', 'value' => '970905-10-6721'],
                ['field_id' => 'primary_identification.type', 'value' => 'nric'],
                ['field_id' => 'relationships[].relationship', 'value' => 'spouse'],
                ['field_id' => 'relationships[].related_person.full_legal_name', 'value' => 'Suhaimi Bin Shafain'],
                ['field_id' => 'relationships[].related_person.identification_number', 'value' => '810506036065'],
            ],
        ]]));

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $this->actingAs($staff)->get(route('documents.show', $scan))
            ->assertOk()
            ->assertSeeInOrder(['Relationship To Applicant', 'NEXT OF KIN', 'Same gender'])
            ->assertSeeInOrder(['Other Relationship', 'NEXT OF KIN', 'Same gender'])
            ->assertSee('MALE')
            ->assertSee('Is Next Of Kin</li>', false)
            ->assertDontSee('SPOUSE')
            ->assertDontSee('MARRIED');
    }

    public function test_a_female_applicant_and_female_relative_are_next_of_kin_instead_of_spouse(): void
    {
        $staff = User::factory()->staff()->create();
        $scan = $this->scan($staff);
        $this->fake($this->payload([[
            'record_ref' => 'applicant_01',
            'source_pages' => [1],
            'values' => [
                ['field_id' => 'customer.full_legal_name', 'value' => 'Siti Aminah Binti Ali'],
                ['field_id' => 'primary_identification.number', 'value' => '990301-06-5314'],
                ['field_id' => 'primary_identification.type', 'value' => 'nric'],
                ['field_id' => 'relationships[].relationship', 'value' => 'spouse'],
                ['field_id' => 'relationships[].related_person.full_legal_name', 'value' => 'Nur Hayani Alya Binti Azhar'],
                ['field_id' => 'relationships[].related_person.identification_number', 'value' => '880202-08-6422'],
            ],
        ]]));

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $this->actingAs($staff)->get(route('documents.show', $scan))
            ->assertOk()
            ->assertSeeInOrder(['Relationship To Applicant', 'NEXT OF KIN', 'Same gender'])
            ->assertSeeInOrder(['Salutation', 'CIK', 'From IC'])
            ->assertSee('FEMALE')
            ->assertDontSee('SPOUSE')
            ->assertDontSee('MARRIED')
            ->assertDontSee('PUAN');
    }

    public function test_a_family_phone_marks_next_of_kin_and_a_missing_phone_does_not(): void
    {
        $staff = User::factory()->staff()->create();
        $scan = $this->scan($staff);
        $this->fake($this->payload([[
            'record_ref' => 'applicant_01',
            'source_pages' => [1],
            'values' => [
                ['field_id' => 'customer.full_legal_name', 'value' => 'Ahmad Bin Ali'],
                ['field_id' => 'relationships[].related_person.full_legal_name', 'value' => 'Siti Aminah'],
                ['field_id' => 'relationships[].relationship', 'value' => 'spouse'],
                ['field_id' => 'relationships[].related_person.mobile', 'value' => '014-3434744'],
            ],
        ]]));

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $this->actingAs($staff)->get(route('documents.show', $scan))
            ->assertOk()
            ->assertSeeInOrder(['Family Member Mobile', '014-3434744', 'Is Next Of Kin', 'YES', 'Default'])
            ->assertSeeInOrder(['Is Primary Next Of Kin', 'YES', 'Default']);
    }

    public function test_a_day_first_employment_date_is_saved_as_a_calendar_date(): void
    {
        $staff = User::factory()->staff()->create();
        $scan = $this->scan($staff);
        $this->fake($this->payload([[
            'record_ref' => 'applicant_01',
            'source_pages' => [1],
            'values' => [
                ['field_id' => 'customer.full_legal_name', 'value' => 'Ahmad Saifuldin bin Abu Bakar'],
                ['field_id' => 'employments[].start_date', 'value' => '14/09/2010'],
                ['field_id' => 'employments[].occupation', 'value' => 'Clerk'],
            ],
        ]]));

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $stored = collect($scan->extractedProfiles()->sole()->original_projection['values'])->pluck('value', 'field_id');
        $this->assertSame('2010-09-14', $stored['employments[].start_date']);
        $this->actingAs($staff)->get(route('documents.show', $scan))
            ->assertOk()
            ->assertSeeInOrder(['Employment Start Date', '2010-09-14']);
    }

    public function test_prompt_asks_for_bank_branch_and_complete_bank_reference_lines(): void
    {
        $snapshot = CustomerProfileSchema::snapshot();
        $pass = app(SinglePassExtraction::class);
        $fields = $pass->fieldList($snapshot);
        $prompt = $pass->prompt($snapshot, 1, 2);

        $this->assertStringContainsString('bank_accounts[].branch_address', $fields);
        $this->assertStringContainsString('When a Bank Reference or bank block prints Bank, Branch, Account type, and Account number', $prompt);
        $this->assertStringContainsString('Do not stop after bank name and account number if branch is printed', $prompt);
        $this->assertStringContainsString('Profile fill prompt '.SinglePassExtraction::PROMPT_VERSION, $prompt);
    }

    public function test_an_empty_terms_batch_may_use_the_page_number_as_its_contract_version(): void
    {
        $snapshot = CustomerProfileSchema::snapshot();
        $pass = app(SinglePassExtraction::class);
        $response = [
            'contract_version' => '13', 'schema_hash' => $snapshot->hash,
            'document_type' => 'Terms', 'title' => 'Terms', 'summary' => 'Terms only.',
            'readable' => true, 'limitations' => 'Contractual terms.', 'records' => [], 'unresolved' => [],
        ];

        $accepted = $pass->accept($response, $snapshot->schema(), $snapshot->hash, 13, 14);

        $this->assertSame([], $accepted['records']);
        $response['records'] = [[
            'record_ref' => 'r1', 'source_pages' => [13], 'warnings' => [],
            'values' => [$this->value('customer.full_legal_name', 'provided', 'Someone', 'Someone', null, 'person-1', 'Applicant', 13)],
        ]];
        $this->expectException(UnexpectedValueException::class);
        $pass->accept($response, $snapshot->schema(), $snapshot->hash, 13, 14);
    }

    public function test_later_slices_of_one_form_fold_into_the_named_applicant(): void
    {
        $staff = User::factory()->staff()->create();
        $scan = $this->scan($staff);
        config()->set('document_scanner.pages_per_batch', 2);
        app()->instance(PdfPageBatches::class, new class extends PdfPageBatches
        {
            public function make($scan, int $size): array
            {
                return ['count' => 6, 'batches' => [1 => '%PDF-1', 3 => '%PDF-3', 5 => '%PDF-5']];
            }
        });
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['status' => 'completed', 'output_text' => json_encode($this->payload([[
                'record_ref' => 'applicant_001', 'source_pages' => [1, 2], 'warnings' => [],
                'values' => [
                    $this->value('customer.full_legal_name', 'provided', 'MOHD NORHISYAM BIN ZAKARIA', 'MOHD NORHISYAM BIN ZAKARIA'),
                    $this->value('primary_identification.number', 'provided', '850325-03-5545', '850325-03-5545'),
                ],
            ]]))])
            ->push(['status' => 'completed', 'output_text' => json_encode($this->payload([[
                'record_ref' => 'applicant_01', 'source_pages' => [1, 2], 'warnings' => [],
                'values' => [
                    $this->value('employments[].employer_name', 'provided', 'ESEY ALUMINIUM GLASS', 'ESEY', '1', 'person-1', 'Applicant', 1),
                    $this->value('bank_accounts[].bank_name', 'provided', 'CIMB', 'CIMB', '1', 'person-1', 'Applicant', 2),
                ],
            ]]))])
            ->push(['status' => 'completed', 'output_text' => json_encode($this->payload([[
                'record_ref' => 'applicant_1', 'source_pages' => [1], 'warnings' => [],
                'values' => [
                    $this->value('customer.full_legal_name', 'provided', 'MOHD NORHISYAM B. ZAKARIA', 'MOHD NORHISYAM B. ZAKARIA'),
                    $this->value('primary_identification.number', 'provided', '850325 03 5545', '850325 03 5545'),
                ],
            ]]))]),
        ]);

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $profile = $scan->extractedProfiles()->sole();
        $this->assertSame([1, 2, 3, 4, 5], $profile->source_pages);
        $values = collect($profile->original_projection['values']);
        $this->assertSame(['MOHD NORHISYAM BIN ZAKARIA'], $values->where('field_id', 'customer.full_legal_name')->pluck('value')->all());
        $this->assertSame(['850325-03-5545'], $values->where('field_id', 'primary_identification.number')->pluck('value')->all());
        $this->assertTrue($values->pluck('value')->contains('ESEY ALUMINIUM GLASS'));
        $this->assertTrue($values->pluck('value')->contains('CIMB'));
        $this->actingAs($staff)->get(route('documents.show', $scan))
            ->assertOk()
            ->assertSee('MOHD NORHISYAM BIN ZAKARIA')
            ->assertSee('Pages 1, 2, 3, 4, 5')
            ->assertDontSee('Unnamed applicant')
            ->assertDontSee('Applicant 2');
    }

    public function test_an_abbreviation_with_a_different_nric_stays_separate(): void
    {
        $scan = $this->scan();
        config()->set('document_scanner.pages_per_batch', 2);
        app()->instance(PdfPageBatches::class, new class extends PdfPageBatches
        {
            public function make($scan, int $size): array
            {
                return ['count' => 4, 'batches' => [1 => '%PDF-1', 3 => '%PDF-3']];
            }
        });
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['status' => 'completed', 'output_text' => json_encode($this->payload([[
                'record_ref' => 'r1', 'source_pages' => [1], 'warnings' => [],
                'values' => [
                    $this->value('customer.full_legal_name', 'provided', 'MOHD NORHISYAM BIN ZAKARIA', 'MOHD NORHISYAM BIN ZAKARIA'),
                    $this->value('primary_identification.number', 'provided', '800101-01-1234', '800101-01-1234'),
                ],
            ]]))])
            ->push(['status' => 'completed', 'output_text' => json_encode($this->payload([[
                'record_ref' => 'r2', 'source_pages' => [3], 'warnings' => [],
                'values' => [
                    $this->value('customer.full_legal_name', 'provided', 'MOHD NORHISYAM B. ZAKARIA', 'MOHD NORHISYAM B. ZAKARIA', null, 'person-1', 'Applicant', 3),
                    $this->value('primary_identification.number', 'provided', '900202-02-2345', '900202-02-2345', null, 'person-1', 'Applicant', 3),
                ],
            ]]))]),
        ]);

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $this->assertSame(2, $scan->extractedProfiles()->count());
    }

    public function test_a_named_slice_after_a_nameless_slice_stays_separate(): void
    {
        $scan = $this->scan();
        config()->set('document_scanner.pages_per_batch', 2);
        app()->instance(PdfPageBatches::class, new class extends PdfPageBatches
        {
            public function make($scan, int $size): array
            {
                return ['count' => 4, 'batches' => [1 => '%PDF-1', 3 => '%PDF-3']];
            }
        });
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['status' => 'completed', 'output_text' => json_encode($this->payload([[
                'record_ref' => 'applicant_01', 'source_pages' => [1, 2], 'warnings' => [],
                'values' => [
                    $this->value('employments[].employer_name', 'provided', 'ESEY ALUMINIUM GLASS', 'ESEY', '1'),
                ],
            ]]))])
            ->push(['status' => 'completed', 'output_text' => json_encode($this->payload([[
                'record_ref' => 'applicant_1', 'source_pages' => [3], 'warnings' => [],
                'values' => [$this->value('customer.full_legal_name', 'provided', 'MOHD NORHISYAM BIN ZAKARIA', 'MOHD NORHISYAM BIN ZAKARIA', null, 'person-1', 'Applicant', 3)],
            ]]))]),
        ]);

        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));

        $this->assertSame(2, $scan->extractedProfiles()->count());
        $this->assertSame('completed', $scan->fresh()->profile_status);
    }

    public function test_a_failed_scan_retry_withdraws_untouched_auto_profiles(): void
    {
        $staff = User::factory()->staff()->create();
        $scan = $this->scan($staff);
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['status' => 'completed', 'output_text' => json_encode($this->payload([[
                'record_ref' => 'r1', 'source_pages' => [1], 'warnings' => [],
                'values' => [$this->value('customer.full_legal_name', 'provided', 'Old Person', 'Old Person')],
            ]]))])
            ->push(['status' => 'completed', 'output_text' => json_encode($this->payload([[
                'record_ref' => 'r1', 'source_pages' => [1], 'warnings' => [],
                'values' => [$this->value('customer.full_legal_name', 'provided', 'New Person', 'New Person')],
            ]]))]),
        ]);
        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));
        $old = $scan->extractedProfiles()->sole();
        $old->reviewEvents()->create([
            'actor_id' => $staff->id, 'revision' => 1, 'reason' => 'Checked', 'created_at' => now(),
        ]);
        $scan->update(['profile_status' => 'failed']);
        Storage::disk('local')->deleteDirectory('document-scans/'.$scan->id.'/profile-batches');

        app(SinglePassExtraction::class)->run($scan->fresh(), app(GeminiDocumentReader::class));

        $this->assertSame('active', $old->fresh()->reviewed_projection['grouping_status']);
        $this->assertSame(2, $scan->extractedProfiles()->count());
    }

    public function test_a_failed_scan_retry_replaces_an_untouched_auto_profile(): void
    {
        $scan = $this->scan();
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['status' => 'completed', 'output_text' => json_encode($this->payload([[
                'record_ref' => 'r1', 'source_pages' => [1], 'warnings' => [],
                'values' => [$this->value('customer.full_legal_name', 'provided', 'Old Person', 'Old Person')],
            ]]))])
            ->push(['status' => 'completed', 'output_text' => json_encode($this->payload([[
                'record_ref' => 'r1', 'source_pages' => [1], 'warnings' => [],
                'values' => [$this->value('customer.full_legal_name', 'provided', 'New Person', 'New Person')],
            ]]))]),
        ]);
        app(SinglePassExtraction::class)->run($scan, app(GeminiDocumentReader::class));
        $replaced = $scan->extractedProfiles()->sole();
        $scan->update(['profile_status' => 'failed']);
        Storage::disk('local')->deleteDirectory('document-scans/'.$scan->id.'/profile-batches');

        app(SinglePassExtraction::class)->run($scan->fresh(), app(GeminiDocumentReader::class));

        $this->assertSame('withdrawn', $replaced->fresh()->reviewed_projection['grouping_status']);
        $active = $scan->extractedProfiles()->get()->filter(
            fn ($profile): bool => ($profile->reviewed_projection['grouping_status'] ?? 'active') !== 'withdrawn',
        );
        $this->assertCount(1, $active);
        $this->assertSame('New Person', $active->first()->original_projection['values'][0]['value']);
    }

    private function scan(?User $staff = null): DocumentScan
    {
        Storage::fake('local');
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite');
        $scan = DocumentScan::factory()->for($staff ?? User::factory()->staff(), 'uploader')->create();
        Storage::disk('local')->put($scan->storage_path, '%PDF-1.4 sample');

        return $scan;
    }

    /** @param array<string, mixed> $payload */
    private function fake(array $payload): void
    {
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed', 'output_text' => json_encode($payload),
        ])]);
    }
}
