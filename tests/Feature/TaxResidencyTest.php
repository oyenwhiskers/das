<?php

namespace Tests\Feature;

use App\Livewire\Customers\TaxResidency;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class TaxResidencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_adding_residency_opens_modal_over_residency_list(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($staff)->test(TaxResidency::class, ['customer' => $customer])
            ->assertDontSee('aria-labelledby="residency-form-title"', false)
            ->call('addResidency')
            ->assertSee('aria-labelledby="residency-form-title"', false)
            ->assertSee('No foreign tax residencies recorded')
            ->call('cancel')
            ->assertDontSee('aria-labelledby="residency-form-title"', false);
    }

    public function test_tax_tab_and_independent_malaysian_tin_save_are_masked(): void
    {
        $customer = Customer::factory()->create();
        $staff = User::factory()->staff()->create();
        $this->actingAs($staff)->get(route('customers.show', $customer).'?tab=tax')
            ->assertOk()->assertSee('Tax &amp; Residency', false)->assertSee('FATCA Self-Certification');
        Livewire::actingAs($staff)->test(TaxResidency::class, ['customer' => $customer])
            ->call('editTin')->set('malaysianTin', '001234567890')->call('saveTin')->assertHasNoErrors()
            ->assertSee('********7890')->assertDontSee('001234567890');

        $this->assertSame('001234567890', $customer->taxIdentification->malaysian_tin);
        $this->assertNotSame('001234567890', DB::table('customer_tax_identifications')->value('malaysian_tin'));
        $this->assertSame(0, $customer->fatcaDeclarations()->count());
        $this->assertSame(0, $customer->taxResidencies()->count());
        $this->assertTrue($customer->activities()->where('action', 'malaysian_tin_added')->exists());
        $this->assertFalse($customer->activities()->get()->pluck('description')->contains(fn ($description) => str_contains($description, '001234567890')));
        Livewire::actingAs($staff)->test(TaxResidency::class, ['customer' => $customer])
            ->call('editTin')->set('malaysianTin', '0123')->call('saveTin')->assertHasNoErrors()
            ->assertSee('****')->assertDontSee('0123');
    }

    public function test_multiple_residencies_duplicate_rejection_and_history(): void
    {
        $customer = Customer::factory()->create();
        $staff = User::factory()->staff()->create();
        $component = Livewire::actingAs($staff)->test(TaxResidency::class, ['customer' => $customer]);
        $component->call('addResidency')->set('residency.jurisdiction', 'Singapore')->set('residency.foreign_tin', '00001234')
            ->call('saveResidency')->assertHasNoErrors();
        $component->call('addResidency')->set('residency.jurisdiction', 'United Kingdom')->set('residency.missing_tin_reason', 'A')
            ->call('saveResidency')->assertHasNoErrors();
        $component->call('addResidency')->set('residency.jurisdiction', ' singapore ')->set('residency.missing_tin_reason', 'C')
            ->call('saveResidency')->assertHasErrors('residency.jurisdiction');
        $this->assertSame(2, $customer->taxResidencies()->count());
        $singapore = $customer->taxResidencies()->where('jurisdiction_key', 'singapore')->firstOrFail();
        $this->assertSame('00001234', $singapore->foreign_tin);
        $this->assertNotSame('00001234', DB::table('customer_tax_residencies')->where('id', $singapore->id)->value('foreign_tin'));
        $component->call('editResidency', $singapore->id)->assertDontSee('00001234')
            ->set('residency.foreign_tin', '00999999')->call('saveResidency')->assertHasNoErrors();
        $this->assertSame(3, $customer->taxResidencies()->count());
        $this->assertNotNull($singapore->fresh()->ended_on);
        $current = $customer->taxResidencies()->where('current_jurisdiction_key', 'singapore')->firstOrFail();
        $component->call('startEndResidency', $current->id)->call('endResidency')->assertHasNoErrors();
        $this->assertNull($current->fresh()->current_jurisdiction_key);
        $this->assertTrue($customer->activities()->where('action', 'tax_residency_ended')->exists());
    }

    public function test_residency_requires_one_tin_basis_and_reason_b_explanation(): void
    {
        $customer = Customer::factory()->create();
        $staff = User::factory()->staff()->create();
        $component = Livewire::actingAs($staff)->test(TaxResidency::class, ['customer' => $customer]);
        $component->call('addResidency')->set('residency.jurisdiction', 'Japan')->call('saveResidency')->assertHasErrors('residency.foreign_tin');
        $component->set('residency.missing_tin_reason', 'B')->call('saveResidency')->assertHasErrors('residency.missing_tin_explanation');
        $component->set('residency.missing_tin_explanation', 'Cannot obtain one')->call('saveResidency')->assertHasNoErrors();
        $component->call('addResidency')->set('residency.jurisdiction', 'Italy')->set('residency.missing_tin_reason', 'C')
            ->call('saveResidency')->assertHasNoErrors();
        $component->call('addResidency')->set('residency.jurisdiction', 'France')->set('residency.foreign_tin', '123')
            ->set('residency.missing_tin_reason', 'C')->call('saveResidency')->assertHasErrors('residency.foreign_tin');
        $this->assertSame(2, $customer->taxResidencies()->count());
    }

    public function test_fatca_classification_completeness_and_replacement_history(): void
    {
        $customer = Customer::factory()->create();
        $staff = User::factory()->staff()->create();
        $component = Livewire::actingAs($staff)->test(TaxResidency::class, ['customer' => $customer]);
        $component->call('editFatca')->set('fatca.us_person_for_tax', 'no')->call('saveFatca')->assertHasNoErrors();
        $first = $customer->fatcaDeclarations()->firstOrFail();
        $this->assertSame('Undetermined', $first->classification());
        $this->assertFalse($first->isComplete());
        $component->call('editFatca')->set('fatca.us_green_card_holder', 'no')->set('fatca.us_resident', 'no')
            ->call('saveFatca')->assertHasNoErrors();
        $second = $customer->fatcaDeclarations()->whereNotNull('current_customer_key')->firstOrFail();
        $this->assertSame('Non-US Person', $second->classification());
        $this->assertTrue($second->isComplete());
        $component->call('editFatca')->set('fatca.us_resident', 'yes')->set('fatca.source', 'customer_attestation')
            ->call('saveFatca')->assertHasNoErrors()->assertSee('W-9 may be required');
        $this->assertSame(3, $customer->fatcaDeclarations()->count());
        $this->assertSame('US Person', $customer->fatcaDeclarations()->whereNotNull('current_customer_key')->firstOrFail()->classification());
        $this->assertNull($first->fresh()->current_customer_key);
        $this->assertSame(2, $customer->activities()->where('action', 'fatca_declaration_updated')->count());
    }

    public function test_explicit_fatca_selection_is_preserved_independently_of_derived_classification(): void
    {
        $customer = Customer::factory()->create();
        $staff = User::factory()->staff()->create();

        Livewire::actingAs($staff)->test(TaxResidency::class, ['customer' => $customer])
            ->call('editFatca')->set('fatca.us_person_for_tax', 'yes')
            ->set('fatca.selected_classification', 'non_us_person')
            ->call('saveFatca')->assertHasNoErrors();

        $record = $customer->fatcaDeclarations()->firstOrFail();
        $this->assertSame('non_us_person', $record->selected_classification);
        $this->assertSame('US Person', $record->classification());
    }

    public function test_inactive_user_and_cross_customer_residency_are_denied(): void
    {
        $customer = Customer::factory()->create();
        $inactive = User::factory()->staff()->inactive()->create();
        Livewire::actingAs($inactive)->test(TaxResidency::class, ['customer' => $customer])->assertForbidden();

        $staff = User::factory()->staff()->create();
        $other = Customer::factory()->create();
        $record = $other->taxResidencies()->create([
            'jurisdiction' => 'Japan', 'jurisdiction_key' => 'japan', 'current_jurisdiction_key' => 'japan',
            'foreign_tin' => '001', 'effective_date' => today()->toDateString(),
        ]);
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($staff)->test(TaxResidency::class, ['customer' => $customer])
            ->call('editResidency', $record->id);
    }

    public function test_stale_fatca_editor_cannot_replace_newer_declaration(): void
    {
        $customer = Customer::factory()->create();
        $staff = User::factory()->staff()->create();
        $first = Livewire::actingAs($staff)->test(TaxResidency::class, ['customer' => $customer])->call('editFatca');
        $second = Livewire::actingAs($staff)->test(TaxResidency::class, ['customer' => $customer])->call('editFatca');
        $first->set('fatca.us_person_for_tax', 'yes')->call('saveFatca')->assertHasNoErrors();
        $second->set('fatca.us_person_for_tax', 'no')->call('saveFatca')->assertHasErrors('fatca.declared_on');
        $this->assertSame(1, $customer->fatcaDeclarations()->count());
    }

    public function test_database_rejects_duplicate_current_jurisdictions(): void
    {
        $customer = Customer::factory()->create();
        $attributes = [
            'jurisdiction' => 'Japan', 'jurisdiction_key' => 'japan', 'current_jurisdiction_key' => 'japan',
            'foreign_tin' => '001', 'effective_date' => today()->toDateString(),
        ];
        $customer->taxResidencies()->create($attributes);

        $this->expectException(QueryException::class);
        $customer->taxResidencies()->create($attributes);
    }

    public function test_fatca_replacement_cannot_predate_current_declaration(): void
    {
        $customer = Customer::factory()->create();
        $staff = User::factory()->staff()->create();
        $component = Livewire::actingAs($staff)->test(TaxResidency::class, ['customer' => $customer]);
        $component->call('editFatca')->set('fatca.us_person_for_tax', 'no')->call('saveFatca')->assertHasNoErrors();
        $component->call('editFatca')->set('fatca.declared_on', today()->subDay()->toDateString())
            ->call('saveFatca')->assertHasErrors('fatca.declared_on');
        $this->assertSame(1, $customer->fatcaDeclarations()->count());
    }
}
