<?php

namespace Tests\Feature;

use App\Livewire\Customers\BankAccounts;
use App\Models\Customer;
use App\Models\CustomerBankAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class BankAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiple_accounts_are_encrypted_masked_and_preserve_leading_zeros(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $component = Livewire::actingAs($staff)->test(BankAccounts::class, ['customer' => $customer]);
        $component->call('add')->set('form.bank_name', 'Maybank')->set('form.account_number', '0012 3456 7890')
            ->set('form.account_holder_name', 'Ahmad Bin Ali')->set('form.is_primary', true)->call('save')->assertHasNoErrors();
        $component->call('add')->set('form.bank_name', 'CIMB')->set('form.account_number', '000077778888')
            ->set('form.account_holder_name', 'Ahmad Bin Ali')->set('form.ownership', 'joint')->call('save')->assertHasNoErrors();

        $first = $customer->bankAccounts()->where('bank_name', 'Maybank')->firstOrFail();
        $this->assertSame('0012 3456 7890', $first->account_number);
        $this->assertNotSame('0012 3456 7890', DB::table('customer_bank_accounts')->where('id', $first->id)->value('account_number'));
        $this->assertSame(2, $customer->bankAccounts()->count());
        $this->assertSame('joint', $customer->bankAccounts()->where('bank_name', 'CIMB')->firstOrFail()->ownership);
        $component->assertSeeInOrder(['Maybank', 'CIMB'])->assertSee('********7890')->assertDontSee('0012 3456 7890');
        $this->assertFalse($customer->activities()->get()->pluck('description')->contains(fn ($description) => str_contains($description, '0012 3456 7890')));
    }

    public function test_duplicate_identity_is_rejected_after_normalization(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $component = Livewire::actingAs($staff)->test(BankAccounts::class, ['customer' => $customer]);
        $component->call('add')->set('form.bank_name', 'May Bank')->set('form.account_number', '0012-3456')
            ->set('form.account_holder_name', 'A')->call('save')->assertHasNoErrors();
        $component->call('add')->set('form.bank_name', ' may  bank ')->set('form.account_number', '00123456')
            ->set('form.account_holder_name', 'B')->call('save')->assertHasErrors('form.account_number');
        $this->assertSame(1, $customer->bankAccounts()->count());
    }

    public function test_primary_replacement_deactivation_and_reactivation_preserve_history(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $component = Livewire::actingAs($staff)->test(BankAccounts::class, ['customer' => $customer]);
        $component->call('add')->set('form.bank_name', 'First')->set('form.account_number', '00111111')
            ->set('form.account_holder_name', 'A')->set('form.is_primary', true)->call('save')->assertHasNoErrors();
        $first = $customer->bankAccounts()->firstOrFail();
        $component->call('add')->set('form.bank_name', 'Second')->set('form.account_number', '00222222')
            ->set('form.account_holder_name', 'A')->set('form.is_primary', true)->call('save')->assertHasNoErrors();
        $second = $customer->bankAccounts()->where('bank_name', 'Second')->firstOrFail();
        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->is_primary);
        $this->assertSame(1, $customer->bankAccounts()->where('is_primary', true)->count());
        $component->call('setActive', $second->id, false)->assertHasNoErrors();
        $this->assertFalse($second->fresh()->is_primary);
        $component->call('setActive', $second->id, true)->assertHasNoErrors();
        $this->assertFalse($second->fresh()->is_primary);
        $this->assertSame(2, $customer->bankAccounts()->count());
        $this->assertTrue($customer->activities()->where('action', 'bank_account_deactivated')->exists());
        $this->assertTrue($customer->activities()->where('action', 'bank_account_reactivated')->exists());
        $this->assertTrue($customer->activities()->where('action', 'primary_bank_account_changed')->exists());
    }

    public function test_number_change_requires_confirmation_and_edit_does_not_reveal_number(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $account = CustomerBankAccount::factory()->for($customer)->create();
        $original = $account->account_number;
        $component = Livewire::actingAs($staff)->test(BankAccounts::class, ['customer' => $customer]);
        $component->call('edit', $account->id)->assertDontSee($original)
            ->set('form.account_number', '00999999')->call('save')->assertHasErrors('confirmNumberChange');
        $this->assertSame($original, $account->fresh()->account_number);
        $component->set('confirmNumberChange', true)->call('save')->assertHasNoErrors();
        $this->assertSame('00999999', $account->fresh()->account_number);
        $this->assertFalse($customer->activities()->get()->pluck('description')->contains(fn ($description) => str_contains($description, '00999999')));
    }

    public function test_inactive_user_and_other_customer_account_are_denied(): void
    {
        $inactive = User::factory()->staff()->inactive()->create();
        $customer = Customer::factory()->create();
        Livewire::actingAs($inactive)->test(BankAccounts::class, ['customer' => $customer])->assertForbidden();
        $staff = User::factory()->staff()->create();
        $other = Customer::factory()->create();
        $account = CustomerBankAccount::factory()->for($other)->create();
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($staff)->test(BankAccounts::class, ['customer' => $customer])->call('edit', $account->id);
    }

    public function test_edit_can_clear_primary_without_changing_number_and_inactive_cannot_be_primary(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $component = Livewire::actingAs($staff)->test(BankAccounts::class, ['customer' => $customer]);
        $component->call('add')->set('form.bank_name', 'Maybank')->set('form.account_number', '00123456')
            ->set('form.account_holder_name', 'A')->set('form.is_primary', true)->call('save')->assertHasNoErrors();
        $account = $customer->bankAccounts()->firstOrFail();
        $component->call('edit', $account->id)->set('form.branch_address', 'Kuala Lumpur')
            ->set('form.is_primary', false)->call('save')->assertHasNoErrors();
        $this->assertSame('00123456', $account->fresh()->account_number);
        $this->assertSame('Kuala Lumpur', $account->fresh()->branch_address);
        $this->assertFalse($account->fresh()->is_primary);
        $component->call('setActive', $account->id, false);
        $component->call('edit', $account->id)->set('form.is_primary', true)->call('save')->assertHasErrors('form.is_primary');
        $this->assertFalse($account->fresh()->is_primary);
    }

    public function test_customer_profile_has_separate_work_and_financial_information_tabs(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();

        $this->actingAs($staff)->get(route('customers.show', $customer).'?tab=financial')
            ->assertOk()->assertSee('Work Information')->assertSee('Financial Information')
            ->assertSee('Employment History')->assertSee('Banking Information')->assertSee('Add Bank Account');
    }

    public function test_stale_primary_updates_leave_only_the_last_selected_account_primary(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $first = CustomerBankAccount::factory()->for($customer)->create();
        $second = CustomerBankAccount::factory()->for($customer)->create();
        $firstEditor = Livewire::actingAs($staff)->test(BankAccounts::class, ['customer' => $customer])->call('edit', $first->id);
        $secondEditor = Livewire::actingAs($staff)->test(BankAccounts::class, ['customer' => $customer])->call('edit', $second->id);

        $firstEditor->set('form.is_primary', true)->call('save')->assertHasNoErrors();
        $secondEditor->set('form.is_primary', true)->call('save')->assertHasNoErrors();

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
        $this->assertSame(1, $customer->bankAccounts()->where('is_primary', true)->count());
    }

    public function test_required_fields_and_account_type_are_validated(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $component = Livewire::actingAs($staff)->test(BankAccounts::class, ['customer' => $customer]);
        $component->call('add')->set('form.bank_name', '')->set('form.bank_country', '')
            ->set('form.account_holder_name', '')->call('save')
            ->assertHasErrors(['form.bank_name', 'form.bank_country', 'form.account_number', 'form.account_holder_name']);
        $component->set('form.bank_name', 'Maybank')->set('form.bank_country', 'Malaysia')
            ->set('form.account_holder_name', 'A')->set('form.account_number', '123456')
            ->set('form.account_type', 'invalid')->call('save')->assertHasErrors('form.account_type');
        $this->assertSame(0, $customer->bankAccounts()->count());
    }

    public function test_edit_rejects_another_accounts_duplicate_identity(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $component = Livewire::actingAs($staff)->test(BankAccounts::class, ['customer' => $customer]);
        $component->call('add')->set('form.bank_name', 'Maybank')->set('form.account_number', '00123456')
            ->set('form.account_holder_name', 'A')->call('save')->assertHasNoErrors();
        $component->call('add')->set('form.bank_name', 'CIMB')->set('form.account_number', '00987654')
            ->set('form.account_holder_name', 'A')->call('save')->assertHasNoErrors();
        $second = $customer->bankAccounts()->where('bank_name', 'CIMB')->firstOrFail();
        $component->call('edit', $second->id)->set('form.bank_name', 'Maybank')->set('form.account_number', '0012 3456')
            ->set('confirmNumberChange', true)->call('save')->assertHasErrors('form.account_number');
        $this->assertSame('CIMB', $second->fresh()->bank_name);
    }

    public function test_database_rejects_two_primary_keys_for_one_customer(): void
    {
        $customer = Customer::factory()->create();
        CustomerBankAccount::factory()->for($customer)->create(['is_primary' => true, 'primary_active_key' => $customer->id]);
        $second = CustomerBankAccount::factory()->for($customer)->create();

        $this->expectException(QueryException::class);
        $second->update(['is_primary' => true, 'primary_active_key' => $customer->id]);
    }
}
