<?php

namespace Tests\Feature;

use App\Enums\AddressType;
use App\Enums\DocumentScanStatus;
use App\Enums\IdentificationType;
use App\Livewire\Documents\Show;
use App\Models\Customer;
use App\Models\CustomerBankAccount;
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
            ->assertSee('Expand all')
            ->assertSee('Collapse all')
            ->assertSee('Auto-Match all')
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

    /** @param  list<array<string, mixed>>  $values
     * @return array{0: DocumentScan, 1: ExtractedProfile} */
    private function reviewed(string $name, array $values): array
    {
        Queue::fake();
        $snapshot = CustomerProfileSchema::snapshot();
        $scan = DocumentScan::factory()->create([
            'status' => DocumentScanStatus::Completed, 'readable' => true, 'mime_type' => 'image/png',
            'profile_schema_hash' => $snapshot->hash,
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
