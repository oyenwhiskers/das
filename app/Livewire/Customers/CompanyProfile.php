<?php

namespace App\Livewire\Customers;

use App\Enums\AddressType;
use App\Enums\ContactType;
use App\Enums\CustomerType;
use App\Livewire\Concerns\NotifiesBrowser;
use App\Models\Customer;
use App\Services\CustomerProfileWrites;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class CompanyProfile extends Component
{
    use AuthorizesRequests;
    use NotifiesBrowser;

    public Customer $customer;

    public ?string $editingSection = null;

    public string $full_legal_name = '';

    public string $identification_number = '';

    public bool $confirmWeakDuplicates = false;

    /** @var list<array{reference: string, full_legal_name: string}> */
    public array $weakMatches = [];

    public string $mobile = '';

    public string $email = '';

    public string $fax = '';

    public string $registered_at = '';

    public string $registration_country = '';

    public string $entity_type = '';

    public string $entity_sub_type = '';

    public string $industry = '';

    public string $business_activity = '';

    public string $authorized_capital_rm = '';

    public string $paid_up_capital_rm = '';

    public string $registered_line_1 = '';

    public string $registered_line_2 = '';

    public string $registered_city = '';

    public string $registered_state = '';

    public string $registered_postcode = '';

    public string $registered_country = '';

    public string $same_as_registered = 'same';

    public string $mailing_line_1 = '';

    public string $mailing_line_2 = '';

    public string $mailing_city = '';

    public string $mailing_state = '';

    public string $mailing_postcode = '';

    public string $mailing_country = '';

    public function mount(Customer $customer): void
    {
        abort_unless($customer->type === CustomerType::Corporate, 404);
        $this->authorize('view', $customer);
        $this->customer = $customer->load(['identifications', 'contacts', 'addresses', 'corporateDetails']);
        $this->fillFromCustomer();
    }

    public function editSection(string $section): void
    {
        $this->authorize('update', $this->customer);
        $this->fillFromCustomer();
        $this->editingSection = $section;
        $this->resetValidation();
    }

    #[On('discard-company-edit')]
    public function cancel(): void
    {
        $this->editingSection = null;
        $this->fillFromCustomer();
        $this->weakMatches = [];
        $this->confirmWeakDuplicates = false;
        $this->resetValidation();
    }

    public function saveCompany(): void
    {
        $this->authorize('update', $this->customer);
        $result = app(CustomerProfileWrites::class)->saveCorporateCompany($this->customer, [
            'full_legal_name' => $this->full_legal_name,
            'identification_number' => $this->identification_number,
            'mobile' => $this->mobile,
            'email' => $this->email,
            'fax' => $this->fax,
            'registered_at' => $this->registered_at,
            'registration_country' => $this->registration_country,
            'entity_type' => $this->entity_type,
            'entity_sub_type' => $this->entity_sub_type,
            'industry' => $this->industry,
            'business_activity' => $this->business_activity,
            'authorized_capital_rm' => $this->authorized_capital_rm,
            'paid_up_capital_rm' => $this->paid_up_capital_rm,
        ], auth()->user(), $this->confirmWeakDuplicates);

        if ($result['weak'] !== []) {
            $this->weakMatches = $result['weak'];

            return;
        }

        $this->refreshCustomer();
        $this->editingSection = null;
        $this->weakMatches = [];
        $this->confirmWeakDuplicates = false;
        $this->dispatch('company-updated');
        $this->notifySuccess('Company information saved.');
    }

    public function saveAddress(): void
    {
        $this->authorize('update', $this->customer);
        app(CustomerProfileWrites::class)->saveCorporateAddress($this->customer, [
            'registered_line_1' => $this->registered_line_1,
            'registered_line_2' => $this->registered_line_2,
            'registered_city' => $this->registered_city,
            'registered_state' => $this->registered_state,
            'registered_postcode' => $this->registered_postcode,
            'registered_country' => $this->registered_country,
            'same_as_registered' => $this->same_as_registered === 'same',
            'mailing_line_1' => $this->mailing_line_1,
            'mailing_line_2' => $this->mailing_line_2,
            'mailing_city' => $this->mailing_city,
            'mailing_state' => $this->mailing_state,
            'mailing_postcode' => $this->mailing_postcode,
            'mailing_country' => $this->mailing_country,
        ], auth()->user());

        $this->refreshCustomer();
        $this->editingSection = null;
        $this->dispatch('company-updated');
        $this->notifySuccess('Address information saved.');
    }

    protected function refreshCustomer(): void
    {
        $this->customer = $this->customer->fresh(['identifications', 'contacts', 'addresses', 'corporateDetails']);
        $this->fillFromCustomer();
    }

    protected function fillFromCustomer(): void
    {
        $details = $this->customer->corporateDetails;
        $registered = $this->customer->addresses->firstWhere('type', AddressType::Registered);
        $mailing = $this->customer->addresses->firstWhere('type', AddressType::Mailing);

        $this->full_legal_name = $this->customer->full_legal_name ?? '';
        $this->identification_number = $this->customer->primary_identification_number ?? '';
        $this->mobile = $this->customer->contacts->firstWhere('type', ContactType::Mobile)?->value
            ?? $this->customer->primary_mobile
            ?? '';
        $this->email = $this->customer->contacts->firstWhere('type', ContactType::Email)?->value
            ?? $this->customer->primary_email
            ?? '';
        $this->fax = $this->customer->contacts->firstWhere('type', ContactType::Fax)?->value ?? '';
        $this->registered_at = $details?->registered_at?->format('Y-m-d') ?? '';
        $this->registration_country = $details?->registration_country ?? '';
        $this->entity_type = $details?->entity_type ?? '';
        $this->entity_sub_type = $details?->entity_sub_type ?? '';
        $this->industry = $details?->industry ?? '';
        $this->business_activity = $details?->business_activity ?? '';
        $this->authorized_capital_rm = $details?->authorized_capital_rm !== null ? (string) $details->authorized_capital_rm : '';
        $this->paid_up_capital_rm = $details?->paid_up_capital_rm !== null ? (string) $details->paid_up_capital_rm : '';

        $this->registered_line_1 = $registered?->line_1 ?? '';
        $this->registered_line_2 = $registered?->line_2 ?? '';
        $this->registered_city = $registered?->city ?? '';
        $this->registered_state = $registered?->state ?? '';
        $this->registered_postcode = $registered?->postcode ?? '';
        $this->registered_country = $registered?->country ?? '';
        $this->same_as_registered = ($mailing?->same_as_residential ?? true) ? 'same' : 'not_same';
        $this->mailing_line_1 = $mailing?->line_1 ?? '';
        $this->mailing_line_2 = $mailing?->line_2 ?? '';
        $this->mailing_city = $mailing?->city ?? '';
        $this->mailing_state = $mailing?->state ?? '';
        $this->mailing_postcode = $mailing?->postcode ?? '';
        $this->mailing_country = $mailing?->country ?? '';
    }

    public function render(): View
    {
        $this->authorize('view', $this->customer);

        return view('livewire.customers.company-profile');
    }
}
