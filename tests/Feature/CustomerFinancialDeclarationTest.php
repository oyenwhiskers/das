<?php

namespace Tests\Feature;

use App\Livewire\Customers\Financial;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerFinancialDeclarationTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_income_selects_the_personal_annual_income_bracket(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $component = Livewire::actingAs($staff)->test(Financial::class, ['customer' => $customer])->call('edit');

        foreach ([
            '2500.00' => 'up_to_30000',
            '2500.01' => '30001_60000',
            '5000.01' => '60001_120000',
            '10000.01' => '120001_180000',
            '15000.01' => '180001_300000',
            '25000.01' => 'over_300000',
            '' => '',
        ] as $monthlyIncome => $annualBracket) {
            $component->set('financial.monthly_income_rm', $monthlyIncome)
                ->assertSet('financial.personal_annual_income_bracket', $annualBracket);
        }
    }

    public function test_staff_can_correct_the_suggested_annual_income_bracket(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($staff)->test(Financial::class, ['customer' => $customer])
            ->call('edit')
            ->set('financial.monthly_income_rm', '2500.00')
            ->set('financial.personal_annual_income_bracket', '30001_60000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('30001_60000', $customer->financialDeclarations()->firstOrFail()->personal_annual_income_bracket);
    }

    public function test_staff_can_record_independent_amount_ranges_ownership_and_multiple_wealth_sources(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($staff)->test(Financial::class, ['customer' => $customer])
            ->set('financial.monthly_income_rm', '0002500.75')
            ->set('financial.personal_annual_income_bracket', '30001_60000')
            ->set('financial.joint_annual_income_bracket', '100001_200000')
            ->set('financial.net_asset_bracket', '200001_500000')
            ->set('financial.portfolio_bracket', 'source_fifth_range')
            ->set('financial.portfolio_ownership', 'joint_with_spouse')
            ->set('financial.wealth_sources', ['salary', 'rental_income', 'other'])
            ->set('financial.other_wealth_source', 'Consulting')
            ->call('save')->assertHasNoErrors();

        $record = $customer->financialDeclarations()->firstOrFail();
        $this->assertSame('2500.75', $record->monthly_income_rm);
        $this->assertSame('30001_60000', $record->personal_annual_income_bracket);
        $this->assertSame('100001_200000', $record->joint_annual_income_bracket);
        $this->assertSame('200001_500000', $record->net_asset_bracket);
        $this->assertSame('source_fifth_range', $record->portfolio_bracket);
        $this->assertSame('joint_with_spouse', $record->portfolio_ownership);
        $this->assertSame(['salary', 'rental_income', 'other'], $record->wealth_sources);
        $this->assertSame('Consulting', $record->other_wealth_source);
        $this->assertSame($staff->id, $record->recorded_by);
        $this->assertSame(1, $customer->activities()->where('action', 'financial_declaration_created')->count());
    }

    public function test_replacement_keeps_prior_financial_declaration_without_inventing_values(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $component = Livewire::actingAs($staff)->test(Financial::class, ['customer' => $customer]);
        $component->set('financial.monthly_income_rm', '1200.00')->call('save')->assertHasNoErrors();
        $original = $customer->financialDeclarations()->firstOrFail();

        $component->call('edit')->set('financial.monthly_income_rm', '')
            ->set('financial.personal_annual_income_bracket', 'up_to_30000')
            ->call('save')->assertHasNoErrors();

        $this->assertNull($original->fresh()->current_customer_key);
        $this->assertSame('1200.00', $original->fresh()->monthly_income_rm);
        $current = $customer->financialDeclarations()->whereNotNull('current_customer_key')->firstOrFail();
        $this->assertNull($current->monthly_income_rm);
        $this->assertSame('up_to_30000', $current->personal_annual_income_bracket);
        $this->assertSame(1, $customer->activities()->where('action', 'financial_declaration_updated')->count());
    }

    public function test_other_wealth_source_requires_details_and_invalid_bracket_is_rejected(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $component = Livewire::actingAs($staff)->test(Financial::class, ['customer' => $customer]);

        $component->set('financial.wealth_sources', ['other'])->call('save')->assertHasErrors('financial.other_wealth_source');
        $component->set('financial.wealth_sources', ['salary'])
            ->set('financial.personal_annual_income_bracket', 'not_a_range')
            ->call('save')->assertHasErrors('financial.personal_annual_income_bracket');

        $this->assertSame(0, $customer->financialDeclarations()->count());
    }

    public function test_inactive_staff_cannot_edit_financial_declarations(): void
    {
        $staff = User::factory()->staff()->inactive()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($staff)->test(Financial::class, ['customer' => $customer])->assertForbidden();

        $this->assertSame(0, $customer->financialDeclarations()->count());
    }

    public function test_stale_financial_editor_cannot_replace_newer_declaration(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $first = Livewire::actingAs($staff)->test(Financial::class, ['customer' => $customer])->call('edit');
        $second = Livewire::actingAs($staff)->test(Financial::class, ['customer' => $customer])->call('edit');

        $first->set('financial.monthly_income_rm', '2000.00')->call('save')->assertHasNoErrors();
        $second->set('financial.monthly_income_rm', '3000.00')->call('save')->assertHasErrors('financial.declared_on');

        $this->assertSame(1, $customer->financialDeclarations()->count());
        $this->assertSame('2000.00', $customer->financialDeclarations()->firstOrFail()->monthly_income_rm);
    }
}
