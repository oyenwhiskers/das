<?php

namespace Tests\Feature;

use App\Enums\AddressType;
use App\Livewire\Customers\WorkFinance;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class EmploymentInformationTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_changes_preserve_history_and_retirement_information(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();
        $component = Livewire::actingAs($staff)->test(WorkFinance::class, ['customer' => $customer]);

        $component->call('editStatus')->set('employmentStatus', 'employed')->call('saveStatus')->assertHasNoErrors();
        $component->call('add')->set('form.occupation', 'Engineer')->set('form.employer_name', 'ABC Sdn Bhd')
            ->set('form.is_primary', true)->call('save')->assertHasNoErrors();
        $job = $customer->employments()->firstOrFail();
        $component->call('edit', $job->id)->set('form.is_current', false)->set('form.end_date', '2026-10-01')
            ->call('save')->assertHasNoErrors();
        $component->call('editStatus')->set('employmentStatus', 'retired')->set('retirementDate', '2026-10-02')
            ->call('saveStatus')->assertHasNoErrors();

        $this->assertSame('retired', $customer->fresh()->employment_status);
        $this->assertSame('2026-10-02', $customer->fresh()->retirement_date->toDateString());
        $this->assertFalse($job->fresh()->is_current);
        $this->assertFalse($job->fresh()->is_primary);
        $this->assertSame(1, $customer->employments()->count());
        $this->assertTrue($customer->activities()->where('action', 'employment_ended')->exists());

        $component->call('editStatus')->set('employmentStatus', 'other')->set('employmentStatusOther', 'Homemaker')
            ->call('saveStatus')->assertHasNoErrors();
        $this->assertSame(1, $customer->employments()->count());
        $this->assertSame('other', $customer->fresh()->employment_status);
        $this->assertSame('Homemaker', $customer->fresh()->employment_status_other);
        $this->assertNull($customer->fresh()->retirement_date);
    }

    public function test_concurrent_jobs_require_confirmation_to_change_primary(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();
        $component = Livewire::actingAs($staff)->test(WorkFinance::class, ['customer' => $customer]);

        $component->call('add')->set('form.employer_name', 'First')->set('form.is_primary', true)->call('save')->assertHasNoErrors();
        $first = $customer->employments()->firstOrFail();
        $component->call('add')->set('form.employer_name', 'Second')->set('form.is_primary', true)
            ->call('save')->assertHasErrors('form.is_primary');
        $this->assertSame(1, $customer->employments()->count());
        $component->set('confirmPrimaryReplacement', true)->call('save')->assertHasNoErrors();

        $this->assertSame(2, $customer->employments()->count());
        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($customer->employments()->where('employer_name', 'Second')->firstOrFail()->is_primary);
    }

    public function test_validation_rejects_invalid_dates_and_current_end_date(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();
        $component = Livewire::actingAs($staff)->test(WorkFinance::class, ['customer' => $customer]);

        $component->call('add')->set('form.arrangement', 'employed')->set('form.start_date', '2026-10-02')->set('form.is_current', false)
            ->set('form.end_date', '2026-10-01')->call('save')->assertHasErrors('form.end_date');
        $component->set('form.is_current', true)->set('form.end_date', '2026-10-03')
            ->call('save')->assertHasErrors('form.end_date');
        $this->assertSame(0, $customer->employments()->count());
    }

    public function test_inactive_staff_cannot_access_employment_and_cross_customer_record_is_hidden(): void
    {
        $inactive = User::factory()->staff()->inactive()->create();
        $customer = Customer::factory()->create();
        Livewire::actingAs($inactive)->test(WorkFinance::class, ['customer' => $customer])->assertForbidden();

        $staff = User::factory()->staff()->create();
        $other = Customer::factory()->create();
        $job = $other->employments()->create(['employer_name' => 'Private']);
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($staff)->test(WorkFinance::class, ['customer' => $customer])->call('edit', $job->id);
    }

    public function test_self_employment_can_be_saved_without_an_employer_and_viewed_in_profile(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();

        Livewire::actingAs($staff)->test(WorkFinance::class, ['customer' => $customer])
            ->call('editStatus')->set('employmentStatus', 'self_employed')->call('saveStatus')->assertHasNoErrors()
            ->call('add')->set('form.arrangement', 'self_employed')->set('form.occupation', 'Consultant')
            ->set('form.registration_number', '202601234567')->set('form.city', 'Kuala Lumpur')
            ->call('save')->assertHasNoErrors()->assertSee('ENTREPRENEUR');

        $job = $customer->employments()->firstOrFail();
        $this->assertSame('ENTREPRENEUR', $job->occupation);
        $this->assertSame('OWNER', $job->employer_name);
        $this->actingAs($staff)->get(route('customers.show', $customer).'?tab=work')
            ->assertOk()->assertSee('Work Information')->assertSee('Employment Status')->assertSee('Employment History');
    }

    public function test_self_employed_blank_office_fields_prefer_mailing_then_residential(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create([
            'employment_status' => 'self_employed',
            'primary_mobile' => '0128834419',
        ]);
        CustomerAddress::query()->create([
            'customer_id' => $customer->id,
            'type' => AddressType::Residential,
            'line_1' => 'LOT 244',
            'line_2' => 'KG BARU',
            'city' => 'Machang',
            'state' => 'Kelantan',
            'postcode' => '18500',
            'country' => 'Malaysia',
        ]);
        CustomerAddress::query()->create([
            'customer_id' => $customer->id,
            'type' => AddressType::Mailing,
            'line_1' => 'Lot 9 Pasar',
            'city' => 'Tanah Merah',
            'state' => 'Kelantan',
            'postcode' => '17500',
            'country' => 'Malaysia',
            'same_as_residential' => false,
        ]);

        Livewire::actingAs($staff)->test(WorkFinance::class, ['customer' => $customer->fresh()])
            ->call('add')
            ->assertSet('form.arrangement', 'self_employed')
            ->assertSet('form.occupation', 'ENTREPRENEUR')
            ->assertSet('form.employer_name', 'OWNER')
            ->assertSet('form.address_line_1', 'Lot 9 Pasar')
            ->assertSet('form.city', 'Tanah Merah')
            ->assertSet('form.postcode', '17500')
            ->assertSet('form.general_telephone', '0128834419')
            ->set('form.occupation', 'BUSINESS')
            ->set('form.address_line_1', 'SUHAIZA CONSTRUCTION')
            ->set('form.address_line_2', 'JALAN PASAR MACHANG')
            ->set('form.city', '')
            ->set('form.postcode', '')
            ->set('form.country', '')
            ->set('form.general_telephone', '')
            ->call('save')->assertHasNoErrors();

        $job = $customer->employments()->firstOrFail();
        $this->assertSame('ENTREPRENEUR', $job->occupation);
        $this->assertSame('SUHAIZA CONSTRUCTION', $job->address_line_1);
        $this->assertSame('JALAN PASAR MACHANG', $job->address_line_2);
        $this->assertSame('Tanah Merah', $job->city);
        $this->assertSame('Kelantan', $job->state);
        $this->assertSame('17500', $job->postcode);
        $this->assertSame('Malaysia', $job->country);
        $this->assertSame('0128834419', $job->general_telephone);
    }

    public function test_other_status_requires_description_and_corrections_are_logged(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();
        $component = Livewire::actingAs($staff)->test(WorkFinance::class, ['customer' => $customer]);

        $component->call('editStatus')->set('employmentStatus', 'other')->call('saveStatus')
            ->assertHasErrors('employmentStatusOther');
        $component->set('employmentStatusOther', 'Student')->call('saveStatus')->assertHasNoErrors();
        $component->call('add')->set('form.employer_name', 'Acme')->call('save')->assertHasNoErrors();
        $job = $customer->employments()->firstOrFail();
        $component->call('edit', $job->id)->set('form.employer_name', 'ACME')->set('isCorrection', true)
            ->call('save')->assertHasNoErrors();

        $this->assertSame('Student', $customer->fresh()->employment_status_other);
        $this->assertSame('ACME', $job->fresh()->employer_name);
        $this->assertTrue($customer->activities()->where('action', 'employment_record_corrected')->exists());
    }

    public function test_arrangement_matches_form_3_and_others_syncs_to_bimb_status(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();
        $component = Livewire::actingAs($staff)->test(WorkFinance::class, ['customer' => $customer]);

        $component->call('add')->set('form.arrangement', 'other')->set('form.occupation', 'Trader')
            ->call('save')->assertHasErrors('form.arrangement_other');
        $component->set('form.arrangement_other', 'KONTRAKTOR')->call('save')->assertHasNoErrors();

        $this->assertSame('other', $customer->fresh()->employment_status);
        $this->assertSame('KONTRAKTOR', $customer->fresh()->employment_status_other);
        $this->assertSame('other', $customer->employments()->firstOrFail()->arrangement);

        $draws = collect(app(\App\Services\Bimbsec\Resolver::class)->resolve($customer->fresh(), [])['draws']);
        $others = $draws->first(fn (array $d): bool => ($d['field']['source']['reference_key'] ?? '') === 'employment.status'
            && ($d['field']['printed_item'] ?? '') === 'Others');
        $this->assertTrue($others['on'] ?? false);
        $this->assertSame('KONTRAKTOR', $draws->first(fn (array $d): bool => ($d['field']['source']['reference_key'] ?? '') === 'employment.status'
            && str_starts_with((string) ($d['field']['printed_item'] ?? ''), 'Others (specify)'))['text'] ?? null);
    }

    public function test_empty_record_and_invalid_contact_details_are_rejected(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();
        $component = Livewire::actingAs($staff)->test(WorkFinance::class, ['customer' => $customer]);

        $component->call('add')->call('save')->assertHasErrors('form.occupation');
        $component->set('form.occupation', 'Engineer')->set('form.general_telephone', 'invalid')
            ->call('save')->assertHasErrors('form.general_telephone');
        $this->assertSame(0, $customer->employments()->count());
    }

    public function test_employment_history_stays_visible_while_adding_and_viewing_records(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();
        $previous = $customer->employments()->create([
            'occupation' => 'Analyst',
            'employer_name' => 'Former Company',
            'start_date' => '2020-01-01',
            'end_date' => '2022-12-31',
            'is_current' => false,
        ]);
        $customer->employments()->create([
            'occupation' => 'Manager',
            'employer_name' => 'Current Company',
            'start_date' => '2023-01-01',
            'is_current' => true,
        ]);

        $component = Livewire::actingAs($staff)->test(WorkFinance::class, ['customer' => $customer]);

        $component->assertSeeInOrder(['Current employment', 'Current Company', 'Previous employment', 'Former Company'])
            ->call('add')
            ->assertSee('Add employment')
            ->assertSee('Current Company')
            ->assertSee('Former Company')
            ->assertSee('role="dialog"', escape: false)
            ->call('cancel')
            ->call('view', $previous->id)
            ->assertSee('Former Company')
            ->assertSee('Current Company')
            ->assertSee('employment-details-title');
    }

    public function test_status_card_and_history_card_keep_editor_and_employment_order_clear(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();
        $customer->employments()->create(['employer_name' => 'Former Company', 'is_current' => false, 'start_date' => '2024-01-01']);
        $customer->employments()->create(['employer_name' => 'Other Current Company', 'is_current' => true, 'start_date' => '2026-01-01']);
        $customer->employments()->create(['employer_name' => 'Primary Company', 'is_current' => true, 'is_primary' => true, 'start_date' => '2025-01-01']);

        $component = Livewire::actingAs($staff)->test(WorkFinance::class, ['customer' => $customer]);

        $component->assertSeeInOrder(['Employment Status', 'Not specified', 'Change', 'Employment History', '+ Add Employment', 'Primary Company', 'Other Current Company', 'Former Company'])
            ->assertSee('aria-label="Employment Status"', escape: false)
            ->assertDontSee('Employment Information')
            ->assertDontSee('id="employment-status"', escape: false)
            ->call('editStatus')
            ->assertSee('id="employment-status"', escape: false)
            ->assertSee('Save Status')
            ->assertSee('Cancel')
            ->call('cancel')
            ->assertDontSee('id="employment-status"', escape: false)
            ->assertSee('+ Add Employment');
    }

    public function test_industry_search_fills_industry_and_sub_industry_from_employer_details(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite');
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed',
            'output_text' => json_encode(['industry' => 'Manufacturing', 'sub_industry' => 'Aluminium']),
        ])]);

        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create([
            'date_of_birth' => now()->subYears(45)->toDateString(),
            'employment_status' => 'employed',
        ]);
        CustomerAddress::query()->create([
            'customer_id' => $customer->id,
            'type' => AddressType::Residential,
            'city' => 'Shah Alam',
            'state' => 'Selangor',
            'country' => 'Malaysia',
        ]);

        Livewire::actingAs($staff)->test(WorkFinance::class, ['customer' => $customer])
            ->call('add')
            ->set('form.employer_name', 'JEY ALUMINIUM BERHAD')
            ->set('form.occupation', 'Business')
            ->call('searchIndustry')
            ->assertHasNoErrors()
            ->assertSet('form.industry', 'Manufacturing')
            ->assertSet('form.sub_industry', 'Aluminium')
            ->assertDispatched('notify', type: 'success');

        Http::assertSent(fn ($request): bool => ($request['tools'][0]['type'] ?? null) === 'google_search'
            && str_contains((string) $request['input'], 'JEY ALUMINIUM BERHAD')
            && str_contains((string) $request['input'], 'Business')
            && str_contains((string) $request['input'], '"age":45')
            && str_contains((string) $request['input'], 'Shah Alam')
            && str_contains((string) $request['input'], 'age-appropriate')
            && str_contains((string) $request['input'], 'BIMBSEC')
            && str_contains((string) $request['input'], 'at most 32 characters')
            && str_contains((string) $request['input'], 'always return BOTH')
            && str_contains((string) $request['input'], 'MUST be a narrower specialty'));
    }

    public function test_industry_search_requires_employment_or_person_context(): void
    {
        Http::fake();
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create([
            'date_of_birth' => null,
            'employment_status' => null,
            'nationality' => null,
            'country_of_residence' => null,
        ]);

        Livewire::actingAs($staff)->test(WorkFinance::class, ['customer' => $customer])
            ->call('add')
            ->set('form.employer_name', '')
            ->call('searchIndustry')
            ->assertHasErrors('form.employer_name');

        Http::assertNothingSent();
    }

    public function test_industry_search_can_recommend_from_customer_profile_without_employer(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite');
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed',
            'output_text' => json_encode(['industry' => 'Professional Services', 'sub_industry' => 'Accounting']),
        ])]);

        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create([
            'date_of_birth' => now()->subYears(60)->toDateString(),
            'employment_status' => 'employed',
        ]);
        CustomerAddress::query()->create([
            'customer_id' => $customer->id,
            'type' => AddressType::Residential,
            'city' => 'Kuala Lumpur',
            'state' => 'W.P. Kuala Lumpur',
            'country' => 'Malaysia',
        ]);

        Livewire::actingAs($staff)->test(WorkFinance::class, ['customer' => $customer])
            ->call('add')
            ->set('form.occupation', 'Accountant')
            ->call('searchIndustry')
            ->assertHasNoErrors()
            ->assertSet('form.industry', 'Professional Services')
            ->assertSet('form.sub_industry', 'Accounting');

        Http::assertSent(fn ($request): bool => str_contains((string) $request['input'], '"age":60')
            && str_contains((string) $request['input'], 'Kuala Lumpur')
            && str_contains((string) $request['input'], 'Accountant')
            && str_contains((string) $request['input'], 'construction'));
    }

    public function test_industry_search_leaves_fields_when_uncertain(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite');
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed',
            'output_text' => json_encode(['industry' => null, 'sub_industry' => null]),
        ])]);

        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();

        Livewire::actingAs($staff)->test(WorkFinance::class, ['customer' => $customer])
            ->call('add')
            ->set('form.employer_name', 'Unknown Co')
            ->set('form.industry', 'Existing')
            ->set('form.sub_industry', 'Keep')
            ->call('searchIndustry')
            ->assertSet('form.industry', 'Existing')
            ->assertSet('form.sub_industry', 'Keep')
            ->assertDispatched('notify', type: 'error');
    }

    public function test_industry_search_completes_blank_sub_industry_using_existing_industry_context(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite');
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed',
            'output_text' => json_encode(['industry' => 'Construction', 'sub_industry' => 'General Building']),
        ])]);

        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create([
            'date_of_birth' => now()->subYears(40)->toDateString(),
            'employment_status' => 'employed',
        ]);

        Livewire::actingAs($staff)->test(WorkFinance::class, ['customer' => $customer])
            ->call('add')
            ->set('form.employer_name', 'ROHADI CONTRACTION')
            ->set('form.industry', 'CONTRACTION')
            ->set('form.sub_industry', '')
            ->call('searchIndustry')
            ->assertSet('form.industry', 'Construction')
            ->assertSet('form.sub_industry', 'General Building')
            ->assertDispatched('notify', type: 'success');

        Http::assertSent(fn ($request): bool => str_contains((string) $request['input'], 'CONTRACTION')
            && str_contains((string) $request['input'], 'ROHADI CONTRACTION'));
    }

    public function test_industry_search_truncates_labels_to_bimbsec_cell_capacity(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite');
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed',
            'output_text' => json_encode([
                'industry' => 'Professional Business Advisory And Consulting Services Group',
                'sub_industry' => 'Chartered Public Accounting And Audit Practice Firm',
            ]),
        ])]);

        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();

        Livewire::actingAs($staff)->test(WorkFinance::class, ['customer' => $customer])
            ->call('add')
            ->set('form.employer_name', 'Example Advisory Sdn Bhd')
            ->call('searchIndustry')
            ->assertSet('form.industry', 'Professional Business Advisory A')
            ->assertSet('form.sub_industry', 'Chartered Public Accounting And');

        $this->assertLessThanOrEqual(32, mb_strlen('Professional Business Advisory A'));
        $this->assertLessThanOrEqual(32, mb_strlen('Chartered Public Accounting And'));
    }

    public function test_industry_search_rejects_incomplete_or_identical_pairs(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.1-flash-lite');
        Http::preventStrayRequests();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['status' => 'completed', 'output_text' => json_encode(['industry' => 'Manufacturing', 'sub_industry' => null])])
                ->push(['status' => 'completed', 'output_text' => json_encode(['industry' => 'Manufacturing', 'sub_industry' => 'Manufacturing'])]),
        ]);

        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();

        Livewire::actingAs($staff)->test(WorkFinance::class, ['customer' => $customer])
            ->call('add')
            ->set('form.employer_name', 'Example Co')
            ->set('form.industry', 'Keep')
            ->set('form.sub_industry', 'Also')
            ->call('searchIndustry')
            ->assertSet('form.industry', 'Keep')
            ->assertSet('form.sub_industry', 'Also')
            ->call('searchIndustry')
            ->assertSet('form.industry', 'Keep')
            ->assertSet('form.sub_industry', 'Also');
    }
}
