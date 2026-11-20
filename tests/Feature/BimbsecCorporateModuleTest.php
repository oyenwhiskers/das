<?php

namespace Tests\Feature;

use App\Enums\AddressType;
use App\Enums\CustomerType;
use App\Enums\IdentificationType;
use App\Enums\RepresentativeRole;
use App\Livewire\Bimbsec\Index as BimbsecIndex;
use App\Livewire\BimbsecCorporate\Index as CorporateIndex;
use App\Livewire\BimbsecCorporate\Show as CorporateShow;
use App\Livewire\Customers\Show as CustomersShow;
use App\Models\BimbsecApplication;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerCorporateDetails;
use App\Models\CustomerIdentification;
use App\Models\CustomerRelationship;
use App\Models\RelatedPerson;
use App\Models\User;
use App\Services\Bimbsec\CorporateBatchRunner;
use App\Services\Bimbsec\CorporateCatalog;
use App\Services\Bimbsec\CorporateGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BimbsecCorporateModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_corporate_index_lists_only_corporates_and_individual_index_excludes_them(): void
    {
        $user = User::factory()->staff()->create();
        $individual = Customer::factory()->for($user, 'creator')->create([
            'type' => CustomerType::Individual,
            'full_legal_name' => 'Individual Person',
        ]);
        $corporate = Customer::factory()->for($user, 'creator')->create([
            'type' => CustomerType::Corporate,
            'full_legal_name' => 'Acme Holdings Sdn Bhd',
        ]);

        Livewire::actingAs($user)
            ->test(CorporateIndex::class)
            ->assertSee('Acme Holdings Sdn Bhd')
            ->assertDontSee('Individual Person');

        Livewire::actingAs($user)
            ->test(BimbsecIndex::class)
            ->assertSee('Individual Person')
            ->assertDontSee('Acme Holdings Sdn Bhd');

        $this->assertTrue($individual->exists);
        $this->assertTrue($corporate->exists);
    }

    public function test_generate_maps_company_fields_and_choices(): void
    {
        Storage::fake('local');
        $user = User::factory()->staff()->create();
        $customer = $this->corporateCustomer($user, [
            'full_legal_name' => 'Beta Capital Sdn Bhd',
            'primary_identification_number' => '20200111223',
        ]);
        CustomerCorporateDetails::query()->updateOrCreate(
            ['customer_id' => $customer->id],
            ['sst' => 'W10-1234', 'aum_bracket' => 'up_to_1000000', 'entity_type' => 'Companies'],
        );
        CustomerAddress::query()->create([
            'customer_id' => $customer->id,
            'type' => AddressType::Registered,
            'line_1' => 'Level 1 Tower A',
            'city' => 'Kuala Lumpur',
            'postcode' => '50450',
            'state' => 'Wilayah Persekutuan',
            'country' => 'MY',
        ]);
        $person = RelatedPerson::query()->create([
            'full_legal_name' => 'Ali Contact',
            'identification_type' => IdentificationType::Nric,
            'identification_number' => '900101145678',
            'mobile' => '0123456789',
            'email' => 'ali@example.com',
            'occupation' => 'Director',
        ]);
        CustomerRelationship::query()->create([
            'customer_id' => $customer->id,
            'related_person_id' => $person->id,
            'representative_role' => RepresentativeRole::PrimaryContact,
            'relationship' => 'other',
        ]);
        $signatory1 = RelatedPerson::query()->create([
            'full_legal_name' => 'Daniel Tan',
            'identification_type' => IdentificationType::Nric,
            'identification_number' => '780215085432',
            'occupation' => 'Managing Director',
        ]);
        CustomerRelationship::query()->create([
            'customer_id' => $customer->id,
            'related_person_id' => $signatory1->id,
            'representative_role' => RepresentativeRole::AuthorizedSignatory,
            'signatory_order' => 1,
            'relationship' => 'other',
        ]);
        $signatory2 = RelatedPerson::query()->create([
            'full_legal_name' => 'Mei Ling Ong',
            'identification_type' => IdentificationType::Nric,
            'identification_number' => '900330145123',
            'occupation' => 'Finance Director',
        ]);
        CustomerRelationship::query()->create([
            'customer_id' => $customer->id,
            'related_person_id' => $signatory2->id,
            'representative_role' => RepresentativeRole::AuthorizedSignatory,
            'signatory_order' => 2,
            'relationship' => 'other',
        ]);

        $application = $customer->bimbsecApplications()->create([
            'form_kind' => BimbsecApplication::KIND_CORPORATE,
            'choices' => BimbsecApplication::normalizeChoices([
                'account_type' => 'margin',
                'margin_credit_limit' => '100000',
                'online_trading' => 'yes',
                'payment' => 'cheque',
            ]),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $document = app(CorporateGenerator::class)->generate($application, $user);
        $this->assertTrue(Storage::disk('local')->exists($document->storage_path));
        $this->assertSame(16, $document->page_count);

        $resolved = (new \App\Services\Bimbsec\CorporateResolver(new CorporateCatalog))
            ->resolve($customer->fresh(), $application->choices);
        $texts = collect($resolved['draws'])->pluck('text')->filter()->implode(' ');
        $this->assertStringContainsString('Beta Capital Sdn Bhd', $texts);
        $this->assertStringContainsString('20200111223', $texts);
        $this->assertStringContainsString('Ali Contact', $texts);
        $this->assertStringContainsString('W10-1234', $texts);
        $this->assertTrue(collect($resolved['draws'])->contains(fn (array $draw): bool => ($draw['field']['source']['reference_key'] ?? '') === 'application.execution.authorized_signatory_1.name' && $draw['text'] === 'Daniel Tan'));
        $this->assertTrue(collect($resolved['draws'])->contains(fn (array $draw): bool => ($draw['field']['source']['reference_key'] ?? '') === 'application.execution.authorized_signatory_1.identity_number' && $draw['text'] === '780215085432'));
        $this->assertTrue(collect($resolved['draws'])->contains(fn (array $draw): bool => ($draw['field']['source']['reference_key'] ?? '') === 'application.execution.authorized_signatory_1.designation' && $draw['text'] === 'Managing Director'));
        $this->assertTrue(collect($resolved['draws'])->contains(fn (array $draw): bool => ($draw['field']['source']['reference_key'] ?? '') === 'application.execution.authorized_signatory_2.name' && $draw['text'] === 'Mei Ling Ong'));
        $this->assertTrue(collect($resolved['draws'])->contains(fn (array $draw): bool => ($draw['field']['source']['reference_key'] ?? '') === 'application.execution.authorized_signatory_2.designation' && $draw['text'] === 'Finance Director'));
        $this->assertFalse(collect($resolved['draws'])->contains(fn (array $draw): bool => str_starts_with((string) ($draw['field']['source']['reference_key'] ?? ''), 'application.execution.witness.')));
        $this->assertTrue(collect($resolved['draws'])->contains(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === 'Margin Account' && $draw['on'] === true));
        $this->assertTrue(collect($resolved['draws'])->contains(fn (array $draw): bool => ($draw['field']['source']['reference_key'] ?? '') === 'application.online_trading_requested' && $draw['choice'] === 'yes'));
        $this->assertTrue(collect($resolved['draws'])->contains(fn (array $draw): bool => ($draw['field']['printed_item'] ?? '') === 'To issue cheque payable to us' && $draw['on'] === true));
    }

    public function test_profile_more_opens_corporate_bimbsec_and_individual_still_blocked(): void
    {
        Storage::fake('local');
        $user = User::factory()->staff()->create();
        $corporate = $this->corporateCustomer($user);
        CustomerCorporateDetails::query()->updateOrCreate(['customer_id' => $corporate->id], []);

        Livewire::actingAs($user)
            ->test(CustomersShow::class, ['customer' => $corporate])
            ->call('openBimbsecCorporate')
            ->assertRedirect();

        $this->assertDatabaseHas('bimbsec_applications', [
            'customer_id' => $corporate->id,
            'form_kind' => BimbsecApplication::KIND_CORPORATE,
        ]);

        Livewire::actingAs($user)
            ->test(CustomersShow::class, ['customer' => $corporate])
            ->call('openBimbsec')
            ->assertStatus(404);
    }

    public function test_batch_merge_for_two_corporates(): void
    {
        Storage::fake('local');
        $user = User::factory()->staff()->create();
        $one = $this->corporateCustomer($user, ['full_legal_name' => 'One Sdn Bhd']);
        $two = $this->corporateCustomer($user, ['full_legal_name' => 'Two Sdn Bhd']);
        CustomerCorporateDetails::query()->updateOrCreate(['customer_id' => $one->id], []);
        CustomerCorporateDetails::query()->updateOrCreate(['customer_id' => $two->id], []);

        $batch = app(CorporateBatchRunner::class)->start([$one->id, $two->id], $user);
        $this->assertTrue($batch->complete);
        $this->assertSame('ready', $batch->status);
        $this->assertTrue(Storage::disk('local')->exists($batch->storage_path));
        $this->assertCount(2, collect($batch->summary)->where('ok', true));
    }

    public function test_show_saves_choices_and_shortens_name(): void
    {
        Storage::fake('local');
        $user = User::factory()->staff()->create();
        $customer = $this->corporateCustomer($user, ['full_legal_name' => 'Very Long Corporate Name That Might Overflow The Form Box Sdn Bhd']);
        CustomerCorporateDetails::query()->updateOrCreate(['customer_id' => $customer->id], []);
        $application = $customer->bimbsecApplications()->create([
            'form_kind' => BimbsecApplication::KIND_CORPORATE,
            'choices' => BimbsecApplication::normalizeChoices([]),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        app(CorporateGenerator::class)->generate($application, $user);

        Livewire::actingAs($user)
            ->test(CorporateShow::class, ['customer' => $customer, 'application' => $application])
            ->set('account_type', 'margin')
            ->set('online_trading', 'yes')
            ->set('payment', 'epayment')
            ->set('company_name', 'Short Name Sdn Bhd')
            ->call('saveForm')
            ->assertHasNoErrors();

        $this->assertSame('Short Name Sdn Bhd', $customer->fresh()->full_legal_name);
        $this->assertSame('margin', $application->fresh()->choices['account_type']);
        $this->assertSame('yes', $application->fresh()->choices['online_trading']);
    }

    public function test_corporate_address_cell_capacity_matches_measured_form_boxes(): void
    {
        $catalog = new CorporateCatalog;

        $this->assertSame(96, $catalog->cellCapacity('corporate.registered_address', 'Registered Address'));
        $this->assertSame(96, $catalog->cellCapacity('corporate.mailing_address', 'Mailing Address (if differs from above)'));
    }

    /** @param  array<string, mixed>  $overrides */
    private function corporateCustomer(User $user, array $overrides = []): Customer
    {
        // Form BRN character cells fit 11 digits (new mapping measured count).
        $brn = $overrides['primary_identification_number'] ?? ('20'.str_pad((string) random_int(100000000, 999999999), 9, '0'));
        $customer = Customer::factory()->for($user, 'creator')->create(array_merge([
            'type' => CustomerType::Corporate,
            'full_legal_name' => 'Corporate Entity Sdn Bhd',
            'primary_identification_type' => IdentificationType::BusinessRegistration,
            'primary_identification_number' => $brn,
        ], $overrides));

        CustomerIdentification::query()->create([
            'customer_id' => $customer->id,
            'type' => IdentificationType::BusinessRegistration,
            'issuing_jurisdiction' => 'MY',
            'number' => $customer->primary_identification_number,
            'normalized_number' => preg_replace('/\D+/', '', (string) $customer->primary_identification_number),
            'is_primary' => true,
        ]);

        return $customer;
    }
}
