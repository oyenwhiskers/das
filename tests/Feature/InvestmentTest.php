<?php

namespace Tests\Feature;

use App\Enums\CustomerActivityAction;
use App\Livewire\Customers\Investment;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InvestmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_adding_brokerage_opens_modal_over_account_list(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create();

        Livewire::actingAs($user)->test(Investment::class, ['customer' => $customer])
            ->assertDontSee('aria-labelledby="brokerage-form-title"', false)
            ->call('addBrokerage')
            ->assertSee('aria-labelledby="brokerage-form-title"', false)
            ->assertSee('No brokerage accounts recorded yet')
            ->call('cancelBrokerage')
            ->assertDontSee('aria-labelledby="brokerage-form-title"', false);
    }

    public function test_investment_tab_shows_unprovided_information_and_unknown_brokerage_status(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create();

        $this->actingAs($user)->get(route('customers.show', $customer))->assertOk()->assertSee('Investment');
        Livewire::actingAs($user)->test(Investment::class, ['customer' => $customer])
            ->assertSee('Not provided')
            ->assertSee('Other accounts may exist');
    }

    public function test_explicit_no_other_broker_answer_is_distinct_from_zero_accounts_and_keeps_history(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $component = Livewire::actingAs($user)->test(Investment::class, ['customer' => $customer]);
        $this->assertSame(0, $customer->brokerageDeclarations()->count());

        $component->call('editBrokerageDeclaration')->set('brokerageDeclaration.answer', 'no')
            ->call('saveBrokerageDeclaration')->assertHasNoErrors();
        $component->call('editBrokerageDeclaration')->set('brokerageDeclaration.answer', 'yes')
            ->call('saveBrokerageDeclaration')->assertHasNoErrors();

        $this->assertSame(['no', 'yes'], $customer->brokerageDeclarations()->orderBy('id')->pluck('answer')->all());
        $this->assertSame(0, $customer->brokerageAccounts()->count());
        $this->assertSame(1, $customer->brokerageDeclarations()->whereNull('current_customer_key')->count());
        $this->assertSame(1, $customer->activities()->where('action', CustomerActivityAction::BrokerageDeclarationUpdated)->count());
    }

    public function test_stale_other_broker_answer_cannot_replace_newer_answer(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $first = Livewire::actingAs($user)->test(Investment::class, ['customer' => $customer])->call('editBrokerageDeclaration');
        $second = Livewire::actingAs($user)->test(Investment::class, ['customer' => $customer])->call('editBrokerageDeclaration');

        $first->set('brokerageDeclaration.answer', 'no')->call('saveBrokerageDeclaration')->assertHasNoErrors();
        $second->set('brokerageDeclaration.answer', 'yes')->call('saveBrokerageDeclaration')->assertHasErrors('brokerageDeclaration.declared_on');

        $this->assertSame(['no'], $customer->brokerageDeclarations()->pluck('answer')->all());
    }

    public function test_profile_saves_multiple_selections_and_preserves_prior_declaration(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create();

        Livewire::actingAs($user)->test(Investment::class, ['customer' => $customer])
            ->set('profile.objectives', ['short_term', 'dividend_income'])
            ->set('profile.risk_tolerance', 'conservative')
            ->set('profile.trading_knowledge', 'advanced')
            ->set('profile.products', ['local_equities', 'foreign_equities'])
            ->call('saveProfile')->assertHasNoErrors();
        Livewire::actingAs($user)->test(Investment::class, ['customer' => $customer])
            ->set('profile.objectives', ['capital_growth'])
            ->set('profile.products', ['share_margin'])
            ->call('saveProfile')->assertHasNoErrors();

        $profiles = $customer->investmentProfiles()->orderBy('id')->get();
        $this->assertCount(2, $profiles);
        $this->assertSame(['short_term', 'dividend_income'], $profiles[0]->objectives);
        $this->assertSame(['local_equities', 'foreign_equities'], $profiles[0]->products);
        $this->assertSame('conservative', $profiles[0]->risk_tolerance);
        $this->assertSame('advanced', $profiles[0]->trading_knowledge);
        $this->assertSame('customer_declared', $profiles[0]->information_source);
        $this->assertSame($user->id, $profiles[0]->recorded_by);
        $this->assertNotNull($profiles[0]->declared_at);
        $this->assertSame(['capital_growth'], $customer->latestInvestmentProfile->objectives);
        $this->assertSame(1, $customer->activities()->where('action', CustomerActivityAction::InvestmentProfileCreated)->count());
        $this->assertSame(1, $customer->activities()->where('action', CustomerActivityAction::InvestmentProfileUpdated)->count());
    }

    public function test_other_product_requires_details_and_incomplete_profile_is_allowed(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create();

        Livewire::actingAs($user)->test(Investment::class, ['customer' => $customer])
            ->set('profile.products', ['other'])->call('saveProfile')
            ->assertHasErrors(['profile.other_product_details']);
        $this->assertDatabaseCount('customer_investment_profiles', 0);

        Livewire::actingAs($user)->test(Investment::class, ['customer' => $customer])
            ->set('profile.products', ['other'])
            ->set('profile.other_product_details', 'Unit trusts')
            ->call('saveProfile')->assertHasNoErrors();
        $this->assertSame('Unit trusts', $customer->latestInvestmentProfile()->first()->other_product_details);

        Livewire::actingAs($user)->test(Investment::class, ['customer' => $customer])
            ->set('profile.objectives', [])->set('profile.products', [])
            ->set('profile.risk_tolerance', '')->set('profile.trading_knowledge', '')
            ->call('saveProfile')->assertHasNoErrors();
        $this->assertSame([], $customer->latestInvestmentProfile()->first()->products);
        $this->assertNull($customer->latestInvestmentProfile()->first()->risk_tolerance);
    }

    public function test_brokerage_accounts_allow_multiple_types_and_confirmed_same_broker_accounts(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create();

        Livewire::actingAs($user)->test(Investment::class, ['customer' => $customer])
            ->set('brokerage.broker_name', 'BIMB Securities')
            ->set('brokerage.account_type', 'trading')
            ->call('saveBrokerage')->assertHasNoErrors();
        Livewire::actingAs($user)->test(Investment::class, ['customer' => $customer])
            ->set('brokerage.broker_name', 'BIMB Securities')
            ->set('brokerage.account_type', 'margin')
            ->call('saveBrokerage')->assertHasNoErrors();
        Livewire::actingAs($user)->test(Investment::class, ['customer' => $customer])
            ->set('brokerage.broker_name', ' bimb   securities ')
            ->set('brokerage.account_type', 'trading')
            ->call('saveBrokerage')->assertHasErrors(['confirmDuplicate'])
            ->set('confirmDuplicate', true)
            ->call('saveBrokerage')->assertHasNoErrors();

        $this->assertSame(3, $customer->brokerageAccounts()->count());
        $this->assertSame(3, $customer->activities()->where('action', CustomerActivityAction::BrokerageAccountAdded)->count());
    }

    public function test_brokerage_status_changes_preserve_account_and_log_actor(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create();
        $account = $customer->brokerageAccounts()->create(['broker_name' => 'Broker A', 'account_type' => 'trading']);

        Livewire::actingAs($user)->test(Investment::class, ['customer' => $customer])
            ->call('setBrokerageActive', $account->id, false)->assertHasNoErrors()
            ->call('setBrokerageActive', $account->id, true)->assertHasNoErrors();

        $this->assertTrue($account->fresh()->is_active);
        $this->assertSame(1, $customer->activities()->where('action', CustomerActivityAction::BrokerageAccountDeactivated)->where('user_id', $user->id)->count());
        $this->assertSame(1, $customer->activities()->where('action', CustomerActivityAction::BrokerageAccountReactivated)->where('user_id', $user->id)->count());
    }

    public function test_account_edit_does_not_change_investment_profile(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create();
        $profile = $customer->investmentProfiles()->create(['objectives' => ['long_term'], 'products' => [], 'declared_at' => now()]);
        $account = $customer->brokerageAccounts()->create(['broker_name' => 'Old Broker', 'account_type' => 'trading']);

        Livewire::actingAs($user)->test(Investment::class, ['customer' => $customer])
            ->call('editBrokerage', $account->id)
            ->set('brokerage.broker_name', 'New Broker')
            ->call('saveBrokerage')->assertHasNoErrors();

        $this->assertSame('New Broker', $account->fresh()->broker_name);
        $this->assertSame($profile->id, $customer->latestInvestmentProfile()->first()->id);
        $this->assertSame(['long_term'], $profile->fresh()->objectives);
    }

    public function test_inactive_user_cannot_modify_investment_data(): void
    {
        $user = User::factory()->inactive()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)->test(Investment::class, ['customer' => $customer])->assertForbidden();
        $this->assertDatabaseCount('customer_investment_profiles', 0);
    }

    public function test_account_from_another_customer_cannot_be_edited(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create();
        $otherCustomer = Customer::factory()->for($user, 'creator')->create();
        $account = $otherCustomer->brokerageAccounts()->create(['broker_name' => 'Other Broker', 'account_type' => 'margin']);

        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($user)->test(Investment::class, ['customer' => $customer])
            ->call('editBrokerage', $account->id);
    }

    public function test_invalid_profile_choices_and_missing_broker_name_are_rejected(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create();

        Livewire::actingAs($user)->test(Investment::class, ['customer' => $customer])
            ->set('profile.objectives', ['unknown'])
            ->set('profile.risk_tolerance', 'advanced')
            ->call('saveProfile')->assertHasErrors(['profile.objectives.0', 'profile.risk_tolerance']);
        Livewire::actingAs($user)->test(Investment::class, ['customer' => $customer])
            ->set('brokerage.broker_name', '')
            ->call('saveBrokerage')->assertHasErrors(['brokerage.broker_name']);

        $this->assertDatabaseCount('customer_investment_profiles', 0);
        $this->assertDatabaseCount('customer_brokerage_accounts', 0);
    }
}
