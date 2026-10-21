<?php

namespace Tests\Feature;

use App\Enums\AddressType;
use App\Enums\DocumentScanStatus;
use App\Enums\IdentificationType;
use App\Livewire\Documents\Show;
use App\Models\Customer;
use App\Models\CustomerBankAccount;
use App\Models\CustomerEmployment;
use App\Models\DocumentScan;
use App\Models\ExtractedProfile;
use App\Models\User;
use App\Services\CustomerProfileSchema;
use App\Services\MergeExtractedProfile;
use App\Services\ProfileMergePreview;
use App\Services\StoreDocumentFindings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Fixtures\DocumentFindingsFixtures;
use Tests\TestCase;

class ProfileMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_fill_only_empty_keeps_false_zero_no_and_existing_values(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create([
            'full_legal_name' => 'Amina Noor',
            'nationality' => 'Malaysian',
            'race' => 'Malay',
            'country_of_residence' => 'Malaysia',
            'primary_mobile' => '0123456789',
        ]);
        $email = $customer->primary_email;
        $customer->addresses()->create(['type' => AddressType::Mailing, 'same_as_residential' => false, 'line_1' => 'Kept']);
        $customer->financialDeclarations()->create([
            'current_customer_key' => $customer->id, 'monthly_income_rm' => '0', 'wealth_sources' => [], 'declared_on' => today()->toDateString(), 'source' => 'staff_entry', 'recorded_by' => $staff->id,
        ]);
        [$scan, $profile] = $this->reviewed($customer->full_legal_name, [
            $this->value('addresses[mailing].same_as_residential', false),
            $this->value('fatca.us_person_for_tax', 'no'),
            $this->value('financial.monthly_income_rm', '0'),
            $this->value('contacts[mobile].value', '0199999999'),
            $this->value('customer.marital_status', 'Married'),
        ]);

        $merge = $this->commit($staff, $profile, $customer);
        $customer->refresh();
        $this->assertSame('Married', $customer->marital_status);
        $this->assertSame('0123456789', $customer->primary_mobile);
        $this->assertFalse((bool) $customer->addresses()->where('type', AddressType::Mailing)->first()->same_as_residential);
        $this->assertSame('Kept', $customer->addresses()->where('type', AddressType::Mailing)->first()->line_1);
        $this->assertFalse($customer->fatcaDeclarations()->where('current_customer_key', $customer->id)->first()->us_person_for_tax);
        $this->assertSame('0.00', $customer->financialDeclarations()->where('current_customer_key', $customer->id)->first()->monthly_income_rm);
        $this->assertSame(1, $customer->financialDeclarations()->count());
        $this->assertSame($email, $customer->primary_email);
        $this->assertSame($scan->id, $profile->document_scan_id);
        $this->assertSame('fill_empty', $merge->strategy);
        $this->assertTrue(collect($merge->changes)->contains(fn (array $change): bool => $change['field_id'] === 'customer.marital_status' && $change['result'] === 'add'));
        $this->assertFalse(collect($merge->changes)->contains(fn (array $change): bool => $change['field_id'] === 'contacts[mobile].value'));
    }

    public function test_overwrite_replaces_only_provided_values_and_replay_does_not_write_again(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create(['full_legal_name' => 'Old Name', 'race' => 'Malay', 'country_of_residence' => 'Malaysia', 'primary_mobile' => '0123456789']);
        [, $profile] = $this->reviewed('New Name', [
            $this->value('customer.full_legal_name', 'New Name'),
            $this->value('customer.marital_status', 'Married'),
        ]);
        $profile = $profile->fresh();
        $preview = app(ProfileMergePreview::class)->make($profile, $customer, 'overwrite', [], []);
        $merge = app(MergeExtractedProfile::class)->commit($profile, $customer, $staff, 'overwrite', 'same-key', $preview['baseline'], [], []);
        $again = app(MergeExtractedProfile::class)->commit($profile, $customer->fresh(), $staff, 'overwrite', 'same-key', 'stale', [], []);

        $this->assertSame($merge->id, $again->id);
        $this->assertSame('New Name', $customer->fresh()->full_legal_name);
        $this->assertSame('Married', $customer->fresh()->marital_status);
        $this->assertSame('0123456789', $customer->fresh()->primary_mobile);
        $this->assertSame(1, $customer->activities()->where('description', 'Extracted profile r1 merged (overwrite and fill empty).')->count());
        $this->assertDatabaseCount('extracted_profile_merges', 1);
    }

    public function test_bank_identity_updates_the_existing_account_and_activity_omits_the_number(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create(['race' => 'Malay', 'country_of_residence' => 'Malaysia']);
        $account = CustomerBankAccount::factory()->for($customer)->create(['branch_address' => null]);
        [, $profile] = $this->reviewed($customer->full_legal_name, [
            $this->value('bank_accounts[].bank_name', 'Maybank', 'acct-1'),
            $this->value('bank_accounts[].bank_country', 'Malaysia', 'acct-1'),
            $this->value('bank_accounts[].account_number', $account->account_number, 'acct-1'),
            $this->value('bank_accounts[].account_holder_name', $account->account_holder_name, 'acct-1'),
            $this->value('bank_accounts[].account_type', 'savings', 'acct-1'),
            $this->value('bank_accounts[].ownership', 'sole', 'acct-1'),
            $this->value('bank_accounts[].branch_address', 'Jalan Ampang', 'acct-1'),
        ]);
        $sibling = $profile->documentScan->extractedProfiles()->create([
            'record_ref' => 'r2', 'source_pages' => [1], 'field_schema_hash' => $profile->field_schema_hash,
            'field_schema_version' => $profile->field_schema_version, 'prompt_version' => '1', 'contract_version' => '1.0',
            'original_projection' => ['record_ref' => 'r2', 'values' => [$this->value('customer.full_legal_name', 'Other Person')], 'warnings' => []],
            'reviewed_projection' => ['record_ref' => 'r2', 'values' => [$this->value('customer.full_legal_name', 'Other Person')], 'warnings' => []],
            'review_status' => 'reviewed',
        ]);

        $this->commit($staff, $profile, $customer);
        $this->assertSame(1, $customer->bankAccounts()->count());
        $this->assertSame('Jalan Ampang', $account->fresh()->branch_address);
        $this->assertSame(0, $sibling->merges()->count());
        $this->assertFalse($customer->activities()->pluck('description')->contains(fn (?string $description): bool => str_contains((string) $description, $account->account_number)));
    }

    public function test_wrong_customer_stale_preview_and_failed_write_do_not_change_the_customer(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create(['full_legal_name' => 'Amina Noor', 'race' => 'Malay', 'country_of_residence' => 'Malaysia']);
        $customer->identifications()->create([
            'type' => IdentificationType::Nric, 'number' => '900101121234', 'normalized_number' => '900101121234',
            'issuing_jurisdiction' => 'MY', 'is_primary' => true,
        ]);
        $customer->update(['primary_identification_type' => IdentificationType::Nric, 'primary_identification_number' => '900101121234']);
        [, $wrong] = $this->reviewed('Amina Noor', [
            $this->value('primary_identification.type', 'nric'),
            $this->value('primary_identification.number', '800101121234'),
            $this->value('customer.marital_status', 'Married'),
        ]);
        $wrong = $wrong->fresh();
        $preview = app(ProfileMergePreview::class)->make($wrong, $customer, 'fill_empty', [], []);
        $this->assertSame('Primary identification does not match this customer.', $preview['identity']);
        try {
            app(MergeExtractedProfile::class)->commit($wrong, $customer, $staff, 'fill_empty', 'wrong-customer', $preview['baseline'], [], []);
            $this->fail('A mismatched identity was merged.');
        } catch (ValidationException) {
            $this->assertNull($customer->fresh()->marital_status);
        }

        [, $profile] = $this->reviewed('Amina Noor', [$this->value('customer.marital_status', 'Married')]);
        $profile = $profile->fresh();
        $preview = app(ProfileMergePreview::class)->make($profile, $customer, 'fill_empty', [], []);
        $customer->update(['marital_status' => 'Single']);
        try {
            app(MergeExtractedProfile::class)->commit($profile, $customer, $staff, 'fill_empty', 'stale-preview', $preview['baseline'], [], []);
            $this->fail('A stale preview was merged.');
        } catch (ValidationException) {
            $this->assertSame('Single', $customer->fresh()->marital_status);
        }

        $customer->financialDeclarations()->create([
            'current_customer_key' => $customer->id, 'monthly_income_rm' => '10', 'wealth_sources' => [], 'declared_on' => today()->toDateString(), 'source' => 'staff_entry', 'recorded_by' => $staff->id,
        ]);
        [, $rollback] = $this->reviewed('Rolled Back', [
            $this->value('customer.full_legal_name', 'Rolled Back'),
            $this->value('financial.monthly_income_rm', '20'),
            $this->value('financial.declared_on', today()->subDay()->toDateString()),
        ]);
        $rollback = $rollback->fresh();
        $preview = app(ProfileMergePreview::class)->make($rollback, $customer->fresh(), 'overwrite', [], []);
        try {
            app(MergeExtractedProfile::class)->commit($rollback, $customer, $staff, 'overwrite', 'rollback', $preview['baseline'], [], []);
            $this->fail('An invalid declaration was merged.');
        } catch (ValidationException) {
            $this->assertSame('Amina Noor', $customer->fresh()->full_legal_name);
            $this->assertSame(1, $customer->financialDeclarations()->count());
        }
    }

    public function test_an_ambiguous_employment_is_left_unchanged_until_a_row_is_chosen(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create(['race' => 'Malay', 'country_of_residence' => 'Malaysia']);
        $job = $customer->employments()->create([
            'occupation' => 'Analyst', 'employer_name' => 'Former Company', 'is_current' => true,
        ]);
        [, $profile] = $this->reviewed($customer->full_legal_name, [
            $this->value('employments[].industry', 'Banking', 'job-1'),
        ]);
        $profile = $profile->fresh();
        $skipped = app(ProfileMergePreview::class)->make($profile, $customer->fresh(), 'fill_empty', [], []);
        $this->assertTrue($skipped['ready']);
        $this->assertTrue($skipped['rows'][0]['ambiguous']);
        app(MergeExtractedProfile::class)->commit($profile, $customer, $staff, 'fill_empty', 'needs-choice', $skipped['baseline'], [], []);
        $this->assertNull($job->fresh()->industry);

        $choice = ['employments|job-1' => (string) $job->id];
        $preview = app(ProfileMergePreview::class)->make($profile, $customer->fresh(), 'fill_empty', $choice, []);
        app(MergeExtractedProfile::class)->commit($profile, $customer, $staff, 'fill_empty', 'chosen-row', $preview['baseline'], $choice, []);

        $this->assertSame('Banking', $job->fresh()->industry);
        $this->assertSame('Analyst', $job->fresh()->occupation);
    }

    public function test_document_match_writes_the_three_strategies(): void
    {
        $customer = Customer::factory()->create([
            'full_legal_name' => 'Kept Name',
            'race' => 'Chinese',
            'marital_status' => null,
            'country_of_residence' => 'Malaysia',
            'primary_email' => null,
        ]);
        $customer->identifications()->create([
            'type' => IdentificationType::Nric,
            'number' => '900101011234',
            'normalized_number' => '900101011234',
            'issuing_jurisdiction' => 'MY',
            'old_nric' => 'OLD123',
            'is_primary' => true,
        ]);
        [$scan, $profile] = $this->reviewed('Kept Name', [
            $this->value('customer.marital_status', 'Married'),
            $this->value('contacts[email].value', 'Keep.Me@Example.com'),
        ]);
        $inactive = User::factory()->staff()->create(['is_active' => false]);
        $this->actingAs($inactive)->get(route('documents.show', $scan))->assertRedirect(route('login'));

        $staff = User::factory()->staff()->create();
        $match = function (string $strategy) use ($staff, $scan, $profile, $customer): void {
            Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])
                ->call('openMatch', $profile->id)
                ->set('customerQuery', 'Kept')
                ->assertSee($customer->fresh()->full_legal_name)
                ->assertSee('Fill empty only')
                ->assertSee('Overwrite and fill empty')
                ->assertSee('Replace all')
                ->call('selectMatchCustomer', $customer->id)
                ->set('matchStrategy', $strategy)
                ->call('confirmMatch')
                ->assertHasNoErrors()
                ->assertDispatched('matched', message: 'Matched to KEPT NAME.');
        };

        $match('fill_empty');
        $storedName = collect($profile->fresh()->reviewed_projection['values'])->firstWhere('field_id', 'customer.full_legal_name');
        $this->assertSame('KEPT NAME', $storedName['value']);
        $customer->refresh();
        $this->assertSame('KEPT NAME', $customer->full_legal_name);
        $this->assertSame('MARRIED', $customer->marital_status);
        $this->assertSame('MALAYSIA', $customer->country_of_residence);
        $this->assertSame('keep.me@example.com', $customer->primary_email);
        $this->assertSame('Chinese', $customer->race);
        $this->assertSame('OLD123', $customer->identifications()->first()->old_nric);

        $match('overwrite');
        $customer->refresh();
        $this->assertSame('MARRIED', $customer->marital_status);
        $this->assertSame('MALAY', $customer->race);
        $this->assertSame('OLD123', $customer->identifications()->first()->old_nric);

        $match('replace_all');
        $customer->refresh();
        $this->assertNotNull($customer->fresh());
        $this->assertSame('MARRIED', $customer->marital_status);
        $this->assertSame('MALAY', $customer->race);
        $this->assertNull($customer->identifications()->first()?->old_nric);
        $this->assertSame($scan->findings->observations, $scan->fresh()->findings->observations);

        $customer->update(['full_legal_name' => 'Other Person']);
        Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])
            ->call('openMatch', $profile->id)
            ->call('selectMatchCustomer', $customer->id)
            ->set('matchStrategy', 'overwrite')
            ->call('confirmMatch')
            ->assertDispatched('matched', message: 'Matched to KEPT NAME.');
        $this->assertSame('KEPT NAME', $customer->fresh()->full_legal_name);
    }

    public function test_a_bank_account_is_saved_when_ownership_was_not_printed(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create(['full_legal_name' => 'Muhammad Faid Bin Mohd Hassan', 'race' => 'Malay', 'country_of_residence' => 'Malaysia']);
        [, $profile] = $this->reviewed($customer->full_legal_name, [
            $this->value('bank_accounts[].bank_name', 'MAYBANK', 'acct-1'),
            $this->value('bank_accounts[].bank_country', 'Malaysia', 'acct-1'),
            $this->value('bank_accounts[].branch_address', 'Kuala Terengganu', 'acct-1'),
            $this->value('bank_accounts[].account_type', 'savings', 'acct-1'),
            $this->value('bank_accounts[].account_number', '123456789012', 'acct-1'),
            $this->value('bank_accounts[].account_holder_name', 'Muhammad Faid Bin Mohd Hassan', 'acct-1'),
        ]);

        $this->commit($staff, $profile, $customer);

        $account = $customer->fresh()->bankAccounts()->first();
        $this->assertNotNull($account);
        $this->assertSame('MAYBANK', $account->bank_name);
        $this->assertSame('savings', $account->account_type);
        $this->assertSame('123456789012', $account->account_number);
        $this->assertNull($account->ownership);
    }

    public function test_a_bank_account_is_saved_when_account_type_was_not_printed(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create(['full_legal_name' => 'Ahmad Saifuldin Bin Abu Bakar', 'race' => 'Malay', 'country_of_residence' => 'Malaysia']);
        [, $profile] = $this->reviewed($customer->full_legal_name, [
            $this->value('bank_accounts[].bank_name', 'CIMB', 'acct-1'),
            $this->value('bank_accounts[].bank_country', 'Malaysia', 'acct-1'),
            $this->value('bank_accounts[].account_number', '7654695964', 'acct-1'),
            $this->value('bank_accounts[].account_holder_name', 'Ahmad Saifuldin Bin Abu Bakar', 'acct-1'),
        ]);

        $this->commit($staff, $profile, $customer);

        $account = $customer->fresh()->bankAccounts()->first();
        $this->assertNotNull($account);
        $this->assertSame('CIMB', $account->bank_name);
        $this->assertSame('7654695964', $account->account_number);
        $this->assertNull($account->account_type);
    }

    public function test_match_fills_next_of_kin_when_only_other_relationship_was_extracted(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create([
            'full_legal_name' => 'Suhaiza Binti Yaakub',
            'race' => 'Malay',
            'country_of_residence' => 'Malaysia',
        ]);
        [$scan, $profile] = $this->reviewed('Suhaiza Binti Yaakub', [
            $this->value('relationships[].other_relationship', 'Next of kin', '1'),
            $this->value('relationships[].related_person.full_legal_name', 'Ibrahim Bin Ali', '1'),
            $this->value('relationships[].related_person.identification_type', 'nric', '1'),
            $this->value('relationships[].related_person.identification_number', '590202035486', '1'),
            $this->value('relationships[].related_person.mobile', '0139170006', '1'),
            $this->value('relationships[].is_next_of_kin', true, '1'),
            $this->value('relationships[].is_primary_next_of_kin', true, '1'),
        ]);

        Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])
            ->call('openMatch', $profile->id)
            ->call('selectMatchCustomer', $customer->id)
            ->set('matchStrategy', 'fill_empty')
            ->call('confirmMatch')
            ->assertHasNoErrors()
            ->assertDispatched('matched');

        $relationship = $customer->fresh()->relationships()->first();
        $this->assertNotNull($relationship);
        $this->assertSame('other', $relationship->relationship);
        $this->assertSame('NEXT OF KIN', $relationship->other_relationship);
        $this->assertTrue($relationship->is_next_of_kin);
        $this->assertTrue($relationship->is_primary_next_of_kin);
        $this->assertSame('IBRAHIM BIN ALI', $relationship->relatedPerson?->full_legal_name);
    }

    public function test_match_keeps_related_person_number_when_identification_type_is_missing(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create([
            'full_legal_name' => 'Mohd Khairee Bin Hassan',
            'race' => 'Malay',
            'country_of_residence' => 'Malaysia',
            'marital_status' => 'Married',
        ]);
        [$scan, $profile] = $this->reviewed('Mohd Khairee Bin Hassan', [
            $this->value('relationships[].relationship', 'spouse', '1'),
            $this->value('relationships[].related_person.full_legal_name', 'Maya Sari Bt Kliwon', '1'),
            $this->value('relationships[].related_person.identification_number', 'e7428296', '1'),
            $this->value('relationships[].related_person.mobile', '0122841981', '1'),
        ]);

        Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])
            ->call('openMatch', $profile->id)
            ->call('selectMatchCustomer', $customer->id)
            ->set('matchStrategy', 'fill_empty')
            ->call('confirmMatch')
            ->assertHasNoErrors()
            ->assertDispatched('matched');

        $person = $customer->fresh()->relationships()->first()?->relatedPerson;
        $this->assertNotNull($person);
        $this->assertSame('MAYA SARI BT KLIWON', $person->full_legal_name);
        $this->assertNull($person->identification_type);
        $this->assertSame('E7428296', $person->identification_number);
        $this->assertNotNull($person->mobile);
    }

    public function test_unchecked_match_sections_are_left_unchanged(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create([
            'full_legal_name' => 'Section Keep',
            'race' => 'Malay',
            'country_of_residence' => 'Malaysia',
            'marital_status' => null,
        ]);
        $account = CustomerBankAccount::factory()->for($customer)->create(['branch_address' => null]);
        [$scan, $profile] = $this->reviewed('Section Keep', [
            $this->value('customer.marital_status', 'Married'),
            $this->value('bank_accounts[].bank_name', $account->bank_name, 'acct-1'),
            $this->value('bank_accounts[].bank_country', 'Malaysia', 'acct-1'),
            $this->value('bank_accounts[].account_number', $account->account_number, 'acct-1'),
            $this->value('bank_accounts[].account_holder_name', $account->account_holder_name, 'acct-1'),
            $this->value('bank_accounts[].branch_address', 'Jalan Ampang', 'acct-1'),
        ]);

        $component = Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])
            ->call('openMatch', $profile->id)
            ->assertSee('Sections to match')
            ->assertSee('Bank accounts')
            ->assertSee('Personal details')
            ->call('selectMatchCustomer', $customer->id);
        $bankIndex = collect($component->get('matchSections'))->search(fn (array $section): bool => $section['module'] === 'bank_accounts');
        $this->assertNotFalse($bankIndex);
        $component->set('matchSections.'.$bankIndex.'.include', false)
            ->set('matchStrategy', 'overwrite')
            ->call('confirmMatch')
            ->assertHasNoErrors()
            ->assertDispatched('matched');

        $customer->refresh();
        $this->assertSame('MARRIED', $customer->marital_status);
        $this->assertSame(1, $customer->bankAccounts()->count());
        $this->assertNull($account->fresh()->branch_address);
    }

    public function test_matching_with_every_section_unchecked_is_refused(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create([
            'full_legal_name' => 'Section Keep',
            'race' => 'Malay',
            'country_of_residence' => 'Malaysia',
            'marital_status' => null,
        ]);
        [$scan, $profile] = $this->reviewed('Section Keep', [
            $this->value('customer.marital_status', 'Married'),
            $this->value('bank_accounts[].bank_name', 'Maybank', 'acct-1'),
        ]);

        $component = Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])
            ->call('openMatch', $profile->id)
            ->call('selectMatchCustomer', $customer->id);
        foreach (array_keys($component->get('matchSections')) as $index) {
            $component->set('matchSections.'.$index.'.include', false);
        }
        $component->call('confirmMatch')
            ->assertSee('Choose at least one section to match.');

        $this->assertNull($customer->fresh()->marital_status);
        $this->assertDatabaseCount('extracted_profile_merges', 0);
    }

    public function test_only_a_different_identification_blocks_a_match(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create(['full_legal_name' => 'Kept Name', 'race' => 'Malay', 'country_of_residence' => 'Malaysia', 'marital_status' => null]);
        [$scan, $profile] = $this->reviewed('Kept Name', [
            $this->value('customer.marital_status', 'Married'),
            $this->value('bank_accounts[].bank_name', 'Maybank', 'acct-1'),
        ]);

        Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])
            ->call('openMatch', $profile->id)
            ->call('selectMatchCustomer', $customer->id)
            ->call('confirmMatch')
            ->assertHasNoErrors();
        $customer->refresh();
        $this->assertSame('MARRIED', $customer->marital_status);
        $this->assertSame(0, $customer->bankAccounts()->count());

        $customer->identifications()->create([
            'type' => IdentificationType::Nric, 'number' => '900101011234', 'normalized_number' => '900101011234',
            'issuing_jurisdiction' => 'MY', 'is_primary' => true,
        ]);
        $customer->update(['primary_identification_type' => IdentificationType::Nric, 'primary_identification_number' => '900101011234']);
        [$scan, $profile] = $this->reviewed('Kept Name', [
            $this->value('primary_identification.type', 'nric'),
            $this->value('primary_identification.number', '970905106721'),
        ]);
        Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])
            ->call('openMatch', $profile->id)
            ->call('selectMatchCustomer', $customer->id)
            ->call('confirmMatch')
            ->assertSee('Primary identification does not match this customer.');
        $this->assertSame('900101011234', $customer->fresh()->identifications()->first()->number);
    }

    public function test_a_match_miss_stays_on_the_document_page(): void
    {
        $staff = User::factory()->staff()->create();
        [$scan, $profile] = $this->reviewed('Kept Name', []);

        Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])
            ->call('openMatch', $profile->id + 1000)
            ->assertSee('This applicant is not stored on this document, so it cannot be matched.')
            ->call('openMatch', $profile->id)
            ->call('confirmMatch')
            ->assertSee('Choose a customer before matching.');
    }

    public function test_auto_match_selects_the_customer_with_the_same_identification_number(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create([
            'full_legal_name' => 'Kept Name',
            'primary_identification_number' => '900101-14-5678',
        ]);
        $customer->identifications()->create([
            'type' => IdentificationType::Nric,
            'number' => '900101-14-5678',
            'normalized_number' => '900101145678',
            'is_primary' => true,
        ]);
        [$scan, $profile] = $this->reviewed('Kept Name', [
            $this->value('primary_identification.number', '900101-14-5678'),
        ]);

        Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])
            ->assertSee('Auto-Match')
            ->assertSee("matchWithLoader(\$wire, 'autoMatch', 'Finding customer'", false)
            ->assertDontSee('<details open', false)
            ->call('autoMatch', $profile->id)
            ->assertSet('matchCustomerId', (string) $customer->id)
            ->assertSet('matchStrategy', 'fill_empty')
            ->assertSee('Recommended from identification number 900101145678.')
            ->assertHasNoErrors();

        $this->assertSame('Kept Name', $customer->fresh()->full_legal_name);
    }

    public function test_auto_match_falls_back_to_name_when_identification_is_missing(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create(['full_legal_name' => 'Mohd Nasir Bin Mohd Ali']);
        [$scan, $profile] = $this->reviewed('MOHD NASIR BIN MOHD ALI', [
            $this->value('bank_accounts[].bank_name', 'Maybank', 'acct-1'),
            $this->value('bank_accounts[].account_number', '123', 'acct-1'),
        ], 'Bank Statement', 'Account Statement');

        Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])
            ->call('autoMatch', $profile->id)
            ->assertSet('matchCustomerId', (string) $customer->id)
            ->assertSet('recommendCreate', false)
            ->assertSee('Recommended from name MOHD NASIR BIN MOHD ALI.')
            ->assertHasNoErrors();
    }

    public function test_auto_match_lets_the_user_choose_when_several_share_the_name(): void
    {
        $staff = User::factory()->staff()->create();
        $first = Customer::factory()->create(['full_legal_name' => 'Amina Noor']);
        $second = Customer::factory()->create(['full_legal_name' => 'Amina Noor']);
        [$scan, $profile] = $this->reviewed('Amina Noor', [
            $this->value('bank_accounts[].bank_name', 'Maybank', 'acct-1'),
            $this->value('bank_accounts[].account_number', '123', 'acct-1'),
        ], 'Bank Statement', 'Account Statement');

        Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])
            ->call('autoMatch', $profile->id)
            ->assertSet('matchProfileId', $profile->id)
            ->assertSet('matchCustomerId', '')
            ->assertSet('customerQuery', 'AMINA NOOR')
            ->assertSee('More than one customer is named AMINA NOOR. Select which one to match.')
            ->assertSee($first->reference)
            ->assertSee($second->reference)
            ->call('selectMatchCustomer', $second->id)
            ->assertSet('matchCustomerId', (string) $second->id)
            ->assertHasNoErrors();
    }

    public function test_auto_match_all_writes_every_recommended_applicant_with_the_chosen_strategy(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create([
            'full_legal_name' => 'Kept Name',
            'primary_identification_number' => '900101-14-5678',
            'race' => null,
        ]);
        $customer->identifications()->create([
            'type' => IdentificationType::Nric,
            'number' => '900101-14-5678',
            'normalized_number' => '900101145678',
            'is_primary' => true,
        ]);
        [$scan] = $this->reviewed('Kept Name', [
            $this->value('primary_identification.number', '900101-14-5678'),
        ]);

        Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])
            ->assertSee('Find an applicant')
            ->assertSee('View')
            ->assertSee('Auto-Match all')
            ->assertDontSee('Expand all')
            ->assertDontSee('Collapse all')
            ->call('autoMatchAll')
            ->assertSet('matchAll', true)
            ->assertSee('Kept Name')
            ->set('matchStrategy', 'fill_empty')
            ->call('confirmMatchAll')
            ->assertSet('matchAll', false)
            ->assertDispatched('matched', message: 'Matched 1 applicant.');

        $this->assertSame('MALAY', $customer->fresh()->race);
    }

    public function test_auto_match_recommends_creating_a_customer_when_nobody_has_that_identification(): void
    {
        $staff = User::factory()->staff()->create();
        [$scan, $profile] = $this->reviewed('Amina Noor', [
            $this->value('primary_identification.number', '900101-14-5678'),
        ]);

        Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])
            ->call('autoMatch', $profile->id)
            ->assertSet('recommendCreate', true)
            ->assertSee('Create AMINA NOOR and match this applicant.')
            ->assertSee('Create and match')
            ->assertSee('matchWithLoader')
            ->call('confirmMatch')
            ->assertHasNoErrors()
            ->assertDispatched('matched');

        $customer = Customer::query()->where('full_legal_name', 'AMINA NOOR')->first();
        $this->assertNotNull($customer);
        $this->assertSame('900101145678', $customer->identifications()->first()->normalized_number);
        $this->assertSame('MALAY', $customer->race);
    }

    public function test_an_incomplete_identification_is_saved_without_the_number_and_marked_on_the_card(): void
    {
        $staff = User::factory()->staff()->create();
        [$scan] = $this->reviewed('Makhazan Bin Mahmud', [
            $this->value('primary_identification.number', '7704036259'),
        ]);

        Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])
            ->assertSee('This identification was saved without a type. Correct the type and number on the customer.')
            ->assertSee('!', false)
            ->call('autoMatchAll')
            ->set('matchStrategy', 'fill_empty')
            ->call('confirmMatchAll')
            ->assertHasNoErrors()
            ->assertDispatched('matched', message: 'Matched 1 applicant.')
            ->call('autoMatchAll')
            ->assertSet('matchAllRows.0.create', false);

        $customer = Customer::query()->where('full_legal_name', 'MAKHAZAN BIN MAHMUD')->first();
        $this->assertNotNull($customer);
        $this->assertSame('7704036259', $customer->primary_identification_number);
        $this->assertSame(0, $customer->identifications()->count());
        $this->assertSame(1, Customer::query()->where('full_legal_name', 'MAKHAZAN BIN MAHMUD')->count());
    }

    public function test_merging_monthly_income_also_sets_the_personal_annual_bracket(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create([
            'full_legal_name' => 'Salary Customer',
            'race' => 'Malay',
            'country_of_residence' => 'Malaysia',
        ]);
        [, $profile] = $this->reviewed($customer->full_legal_name, [
            $this->value('financial.monthly_income_rm', '3000.00'),
            $this->value('financial.declared_on', today()->toDateString()),
        ]);

        $this->commit($staff, $profile, $customer);

        $financial = $customer->financialDeclarations()->where('current_customer_key', $customer->id)->firstOrFail();
        $this->assertSame('3000.00', $financial->monthly_income_rm);
        $this->assertSame('30001_60000', $financial->personal_annual_income_bracket);
    }

    public function test_fill_empty_treats_the_same_nric_with_different_formatting_as_unchanged(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create([
            'full_legal_name' => 'ABDUL MALIK RADHUAN BIN ALI',
            'race' => 'Malay',
            'country_of_residence' => 'Malaysia',
            'primary_identification_type' => IdentificationType::Nric,
            'primary_identification_number' => '830923-03-6187',
        ]);
        $customer->identifications()->create([
            'type' => IdentificationType::Nric, 'number' => '830923-03-6187', 'normalized_number' => '830923036187',
            'issuing_jurisdiction' => 'MY', 'is_primary' => true,
        ]);
        $person = \App\Models\RelatedPerson::query()->create([
            'full_legal_name' => 'SUHAIMI BIN SHAFAIN',
            'identification_type' => IdentificationType::Nric,
            'identification_number' => '810506-03-6065',
            'normalized_identification_number' => '810506036065',
            'mobile' => '014-3434744',
            'occupation' => null,
        ]);
        $customer->relationships()->create([
            'related_person_id' => $person->id,
            'relationship' => 'other',
            'other_relationship' => 'Next of kin',
            'is_next_of_kin' => true,
            'is_primary_next_of_kin' => true,
            'active_identity_key' => $customer->id.':person:'.$person->id,
        ]);
        [, $profile] = $this->reviewed($customer->full_legal_name, [
            $this->value('primary_identification.type', 'nric'),
            $this->value('primary_identification.number', '830923 03 6187'),
            $this->value('relationships[].related_person.full_legal_name', 'SUHAIMI BIN SHAFAIN', '1'),
            $this->value('relationships[].relationship', 'other', '1'),
            $this->value('relationships[].other_relationship', 'Next of kin', '1'),
            $this->value('relationships[].related_person.identification_type', 'nric', '1'),
            $this->value('relationships[].related_person.identification_number', '810506036065', '1'),
            $this->value('relationships[].related_person.mobile', '014-3434744', '1'),
            $this->value('relationships[].related_person.occupation', 'BUSINESS', '1'),
            $this->value('relationships[].is_next_of_kin', true, '1'),
            $this->value('relationships[].is_primary_next_of_kin', true, '1'),
        ]);

        $preview = app(ProfileMergePreview::class)->make($profile, $customer->fresh(), 'fill_empty', [], []);
        $idLines = collect($preview['lines'])->whereIn('field_id', [
            'primary_identification.number',
            'relationships[].related_person.identification_number',
        ]);
        $this->assertTrue($idLines->every(fn (array $line): bool => $line['action'] === 'skip'));
        $this->commit($staff, $profile, $customer);

        $this->assertSame('BUSINESS', $person->fresh()->occupation);
        $this->assertSame('810506-03-6065', $person->fresh()->identification_number);
        $this->assertSame('830923-03-6187', $customer->fresh()->primary_identification_number);
    }

    public function test_auto_match_all_recommends_creating_an_unknown_applicant(): void
    {
        $staff = User::factory()->staff()->create();
        [$scan] = $this->reviewed('Amina Noor', [
            $this->value('primary_identification.number', '900101-14-5678'),
        ]);

        Livewire::actingAs($staff)->test(Show::class, ['documentScan' => $scan])
            ->call('autoMatchAll')
            ->assertSet('matchAll', true)
            ->assertSee('New customer')
            ->assertSee('AMINA NOOR')
            ->assertSee('Not in the customer list.')
            ->assertSeeInOrder(['All', 'Existing', 'New'])
            ->call('confirmMatchAll')
            ->assertDispatched('matched', message: 'Matched 1 applicant.');

        $this->assertSame(1, Customer::query()->where('full_legal_name', 'AMINA NOOR')->count());
    }

    public function test_bank_statement_mailing_matching_residential_sets_same_as_residential(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create(['full_legal_name' => 'Amina Noor', 'race' => 'Malay', 'country_of_residence' => 'Malaysia']);
        $customer->addresses()->create([
            'type' => AddressType::Residential,
            'line_1' => '12 Jalan Melati', 'line_2' => null, 'postcode' => '50000',
            'city' => 'Kuala Lumpur', 'state' => 'Wilayah Persekutuan', 'country' => 'Malaysia',
            'same_as_residential' => false,
        ]);
        [, $profile] = $this->reviewed($customer->full_legal_name, [
            $this->value('addresses[mailing].line_1', '12 Jalan Melati'),
            $this->value('addresses[mailing].postcode', '50000'),
            $this->value('addresses[mailing].city', 'Kuala Lumpur'),
            $this->value('addresses[mailing].state', 'Wilayah Persekutuan'),
            $this->value('addresses[mailing].country', 'Malaysia'),
        ], 'Bank Statement', 'BASIC SAVINGS ACCOUNT-I');

        $this->commit($staff, $profile, $customer);

        $mailing = $customer->fresh()->addresses()->where('type', AddressType::Mailing)->first();
        $this->assertNotNull($mailing);
        $this->assertTrue((bool) $mailing->same_as_residential);
        $this->assertSame('12 Jalan Melati', $mailing->line_1);
    }

    public function test_bank_statement_fills_self_employed_employment_address_from_mailing(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create([
            'full_legal_name' => 'Amina Noor', 'race' => 'Malay', 'country_of_residence' => 'Malaysia',
            'employment_status' => 'self_employed',
        ]);
        $employment = CustomerEmployment::factory()->selfEmployed()->create([
            'customer_id' => $customer->id,
            'is_primary' => true,
            'is_current' => true,
            'address_line_1' => null,
            'city' => null,
            'postcode' => null,
            'state' => null,
            'country' => null,
        ]);
        [, $profile] = $this->reviewed($customer->full_legal_name, [
            $this->value('addresses[mailing].line_1', 'Lot 9 Pasar'),
            $this->value('addresses[mailing].city', 'Tanah Merah'),
            $this->value('addresses[mailing].postcode', '17500'),
            $this->value('addresses[mailing].state', 'Kelantan'),
            $this->value('addresses[mailing].country', 'Malaysia'),
            $this->value('employments[].arrangement', 'self_employed', 'job-1'),
            $this->value('employments[].employer_name', 'Pasar Besar', 'job-1'),
            $this->value('employments[].occupation', 'Trader', 'job-1'),
        ], 'Bank Statement', 'Account Statement');

        $preview = app(ProfileMergePreview::class)->make($profile->fresh(), $customer->fresh(), 'fill_empty', [
            'employments|job-1' => (string) $employment->id,
        ], []);
        $employmentOp = collect($preview['operations'])->firstWhere('type', 'employments');
        $this->assertNotNull($employmentOp);
        $this->assertSame('Lot 9 Pasar', $employmentOp['payload']['address_line_1']);
        $this->assertSame('Tanah Merah', $employmentOp['payload']['city']);

        app(MergeExtractedProfile::class)->commit(
            $profile->fresh(),
            $customer->fresh(),
            $staff,
            'fill_empty',
            (string) str()->uuid(),
            $preview['baseline'],
            ['employments|job-1' => (string) $employment->id],
            [],
        );

        $employment->refresh();
        $this->assertSame('Lot 9 Pasar', $employment->address_line_1);
        $this->assertSame('Tanah Merah', $employment->city);
    }

    public function test_bank_statement_fills_only_blank_self_employed_office_parts_from_mailing(): void
    {
        $customer = Customer::factory()->create([
            'full_legal_name' => 'Amina Noor',
            'employment_status' => 'self_employed',
        ]);
        $employment = CustomerEmployment::factory()->selfEmployed()->create([
            'customer_id' => $customer->id,
            'is_primary' => true,
            'is_current' => true,
            'address_line_1' => 'PASAR BESAR',
            'city' => null,
            'postcode' => null,
            'state' => null,
            'country' => null,
        ]);
        [, $profile] = $this->reviewed($customer->full_legal_name, [
            $this->value('addresses[mailing].line_1', 'Lot 9 Pasar'),
            $this->value('addresses[mailing].city', 'Tanah Merah'),
            $this->value('addresses[mailing].postcode', '17500'),
            $this->value('addresses[mailing].state', 'Kelantan'),
            $this->value('addresses[mailing].country', 'Malaysia'),
            $this->value('employments[].arrangement', 'self_employed', 'job-1'),
            $this->value('employments[].address_line_1', 'PASAR BESAR', 'job-1'),
            $this->value('employments[].occupation', 'Trader', 'job-1'),
        ], 'Bank Statement', 'Account Statement');

        $preview = app(ProfileMergePreview::class)->make($profile->fresh(), $customer->fresh(), 'overwrite', [
            'employments|job-1' => (string) $employment->id,
        ], []);
        $employmentOp = collect($preview['operations'])->firstWhere('type', 'employments');
        $this->assertSame('PASAR BESAR', $employmentOp['payload']['address_line_1']);
        $this->assertSame('Tanah Merah', $employmentOp['payload']['city']);
        $this->assertSame('17500', $employmentOp['payload']['postcode']);
    }

    public function test_form_self_employed_fills_blank_office_parts_from_residential(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create([
            'full_legal_name' => 'Suhaiza Binti Yaakub',
            'employment_status' => 'self_employed',
        ]);
        $customer->addresses()->create([
            'type' => AddressType::Residential,
            'line_1' => 'LOT 244',
            'city' => 'Machang',
            'state' => 'Kelantan',
            'postcode' => '18500',
            'country' => 'Malaysia',
        ]);
        $employment = CustomerEmployment::factory()->selfEmployed()->create([
            'customer_id' => $customer->id,
            'is_primary' => true,
            'is_current' => true,
            'address_line_1' => 'SUHAIZA CONSTRUCTION',
            'address_line_2' => 'JALAN PASAR MACHANG',
            'state' => 'KELANTAN',
            'city' => null,
            'postcode' => null,
            'country' => null,
        ]);
        [, $profile] = $this->reviewed($customer->full_legal_name, [
            $this->value('customer.employment_status', 'self_employed'),
            $this->value('addresses[residential].line_1', 'LOT 244'),
            $this->value('addresses[residential].city', 'Machang'),
            $this->value('addresses[residential].state', 'Kelantan'),
            $this->value('addresses[residential].postcode', '18500'),
            $this->value('addresses[residential].country', 'Malaysia'),
            $this->value('employments[].arrangement', 'self_employed', 'job-1'),
            $this->value('employments[].occupation', 'BUSINESS', 'job-1'),
            $this->value('employments[].address_line_1', 'SUHAIZA CONSTRUCTION', 'job-1'),
            $this->value('employments[].address_line_2', 'JALAN PASAR MACHANG', 'job-1'),
            $this->value('employments[].state', 'KELANTAN', 'job-1'),
        ], 'Account Opening Form', 'Form 3');

        $preview = app(ProfileMergePreview::class)->make($profile->fresh(), $customer->fresh(), 'overwrite', [
            'employments|job-1' => (string) $employment->id,
        ], []);
        $employmentOp = collect($preview['operations'])->firstWhere('type', 'employments');
        $this->assertNotNull($employmentOp);
        $this->assertSame('SUHAIZA CONSTRUCTION', $employmentOp['payload']['address_line_1']);
        $this->assertSame('JALAN PASAR MACHANG', $employmentOp['payload']['address_line_2']);
        $this->assertSame('KELANTAN', $employmentOp['payload']['state']);
        $this->assertSame('Machang', $employmentOp['payload']['city']);
        $this->assertSame('18500', $employmentOp['payload']['postcode']);
        $this->assertSame('Malaysia', $employmentOp['payload']['country']);

        app(MergeExtractedProfile::class)->commit(
            $profile->fresh(),
            $customer->fresh(),
            $staff,
            'overwrite',
            (string) str()->uuid(),
            $preview['baseline'],
            ['employments|job-1' => (string) $employment->id],
            [],
        );
        $employment->refresh();
        $this->assertSame('Machang', $employment->city);
        $this->assertSame('18500', $employment->postcode);
        $this->assertSame('Malaysia', $employment->country);
    }

    public function test_merge_fills_self_employed_next_of_kin_employer_address_from_residential(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create(['full_legal_name' => 'Ali Bin Abu']);
        $customer->addresses()->create([
            'type' => AddressType::Residential,
            'line_1' => '1 Jalan Damai',
            'city' => 'KL',
            'state' => 'Wilayah',
            'postcode' => '50000',
            'country' => 'Malaysia',
        ]);
        [, $profile] = $this->reviewed($customer->full_legal_name, [
            $this->value('addresses[residential].line_1', '1 Jalan Damai'),
            $this->value('addresses[residential].city', 'KL'),
            $this->value('addresses[residential].state', 'Wilayah'),
            $this->value('addresses[residential].postcode', '50000'),
            $this->value('addresses[residential].country', 'Malaysia'),
            $this->value('relationships[].relationship', 'father', 'kin-1'),
            $this->value('relationships[].related_person.full_legal_name', 'Mazwan Bin Mahmud', 'kin-1'),
            $this->value('relationships[].related_person.occupation', 'BUSINESS', 'kin-1'),
            $this->value('relationships[].related_person.mobile', '0123456789', 'kin-1'),
            $this->value('relationships[].is_next_of_kin', true, 'kin-1'),
            $this->value('relationships[].is_primary_next_of_kin', true, 'kin-1'),
        ], 'Account Opening Form', 'Form 3');

        $preview = app(ProfileMergePreview::class)->make($profile->fresh(), $customer->fresh(), 'fill_empty', [
            'relationships|kin-1' => 'new',
        ], []);
        $relationshipOp = collect($preview['operations'])->firstWhere('type', 'relationships');
        $this->assertNotNull($relationshipOp);
        $this->assertSame('1 Jalan Damai', $relationshipOp['payload']['employerAddressLine1']);
        $this->assertSame('KL', $relationshipOp['payload']['employerCity']);
        $this->assertSame('50000', $relationshipOp['payload']['employerPostcode']);

        app(MergeExtractedProfile::class)->commit(
            $profile->fresh(),
            $customer->fresh(),
            $staff,
            'fill_empty',
            (string) str()->uuid(),
            $preview['baseline'],
            ['relationships|kin-1' => 'new'],
            [],
        );
        $person = $customer->fresh()->relationships()->firstOrFail()->relatedPerson;
        $this->assertSame('1 Jalan Damai', $person->employer_address_line_1);
        $this->assertSame('KL', $person->employer_city);
    }

    public function test_bank_statement_does_not_fill_employed_office_from_mailing(): void
    {
        $customer = Customer::factory()->create([
            'full_legal_name' => 'Amina Noor', 'race' => 'Malay', 'country_of_residence' => 'Malaysia',
            'employment_status' => 'employed',
        ]);
        $employment = CustomerEmployment::factory()->create([
            'customer_id' => $customer->id,
            'arrangement' => 'employed',
            'employer_name' => 'Old Employer',
            'occupation' => 'Clerk',
            'is_primary' => true,
            'is_current' => true,
            'address_line_1' => null,
            'city' => null,
        ]);
        [, $profile] = $this->reviewed($customer->full_legal_name, [
            $this->value('addresses[mailing].line_1', 'Lot 9 Pasar'),
            $this->value('addresses[mailing].city', 'Tanah Merah'),
            $this->value('employments[].arrangement', 'employed', 'job-1'),
            $this->value('employments[].employer_name', 'Maybank', 'job-1'),
            $this->value('employments[].occupation', 'Clerk', 'job-1'),
        ], 'Bank Statement', 'Account Statement');

        $preview = app(ProfileMergePreview::class)->make($profile->fresh(), $customer->fresh(), 'overwrite', [
            'employments|job-1' => (string) $employment->id,
        ], []);
        $employmentOp = collect($preview['operations'])->firstWhere('type', 'employments');
        $this->assertNotNull($employmentOp);
        $this->assertSame('', (string) ($employmentOp['payload']['address_line_1'] ?? ''));
        $this->assertSame('Maybank', $employmentOp['payload']['employer_name']);
    }

    /** @param  list<array<string, mixed>>  $values
     * @return array{0: DocumentScan, 1: ExtractedProfile} */
    private function reviewed(string $name, array $values, ?string $documentType = null, ?string $documentTitle = null): array
    {
        Queue::fake();
        $snapshot = CustomerProfileSchema::snapshot();
        $scan = DocumentScan::factory()->create([
            'status' => DocumentScanStatus::Completed, 'readable' => true, 'mime_type' => 'image/png',
            'profile_schema_hash' => $snapshot->hash,
            'document_type' => $documentType,
            'document_title' => $documentTitle,
            'record_plan' => [['anchor' => 'Applicant', 'regions' => [['page_number' => 1, 'bbox' => null]]]],
        ]);
        app(StoreDocumentFindings::class)->store($scan, DocumentFindingsFixtures::identity($scan->id));
        if (! collect($values)->contains(fn (array $value): bool => $value['field_id'] === 'customer.full_legal_name')) {
            $values[] = $this->value('customer.full_legal_name', $name);
        }
        $projection = ['record_ref' => 'r1', 'values' => $values, 'warnings' => []];
        $profile = $scan->extractedProfiles()->create([
            'record_ref' => 'r1', 'source_pages' => [1], 'field_schema_hash' => $snapshot->hash,
            'field_schema_version' => $snapshot->version, 'prompt_version' => '1', 'contract_version' => '1.0',
            'original_projection' => $projection, 'reviewed_projection' => $projection, 'review_status' => 'reviewed',
        ]);

        return [$scan, $profile];
    }

    /** @return array<string, mixed> */
    private function value(string $field, mixed $value, ?string $row = null): array
    {
        return ['field_id' => $field, 'entity_ref' => 'person-1', 'row_ref' => $row, 'state' => 'provided', 'value' => $value, 'evidence_ids' => ['name']];
    }

    private function commit(User $staff, $profile, Customer $customer)
    {
        $profile = $profile->fresh();
        $customer = $customer->fresh();
        $preview = app(ProfileMergePreview::class)->make($profile, $customer, 'fill_empty', [], []);

        return app(MergeExtractedProfile::class)->commit($profile, $customer, $staff, 'fill_empty', (string) str()->uuid(), $preview['baseline'], [], []);
    }
}
