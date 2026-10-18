<?php

namespace App\Livewire\Customers;

use App\Enums\AddressType;
use App\Enums\ContactType;
use App\Enums\CustomerActivityAction;
use App\Enums\CustomerStatus;
use App\Enums\IdentificationType;
use App\Livewire\Concerns\NotifiesBrowser;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerPepAssociation;
use App\Models\CustomerRelationship;
use App\Services\CustomerActivityLogger;
use App\Services\CustomerDuplicateChecker;
use App\Services\CustomerProfileWrites;
use App\Services\NricParser;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Customer Profile — DAS')]
class Show extends Component
{
    use AuthorizesRequests;
    use NotifiesBrowser;

    public Customer $customer;

    public ?string $editingSection = null;

    public string $salutation = '';

    public string $full_legal_name = '';

    public string $identification_type = '';

    public string $identification_number = '';

    public string $issuing_jurisdiction = '';

    /** @var list<array{reference: string, full_legal_name: string}> */
    public array $weakMatches = [];

    public bool $confirmWeakDuplicates = false;

    public string $old_nric = '';

    public string $date_of_birth = '';

    public string $gender = '';

    public string $nationality = '';

    public string $country_of_residence = 'Malaysia';

    public string $race = '';

    public string $marital_status = '';

    public string $mobile = '';

    public string $home_telephone = '';

    public string $email = '';

    public string $social = '';

    /** @var array<string, string> */
    public array $socialAccounts = ['facebook' => '', 'instagram' => '', 'twitter' => ''];

    public string $residential_line_1 = '';

    public string $residential_line_2 = '';

    public string $residential_city = '';

    public string $residential_state = '';

    public string $residential_postcode = '';

    public string $residential_country = '';

    public string $occupancy_status = '';

    public string $occupancy_other = '';

    public bool $same_as_residential = true;

    public string $mailing_line_1 = '';

    public string $mailing_line_2 = '';

    public string $mailing_city = '';

    public string $mailing_state = '';

    public string $mailing_postcode = '';

    public string $mailing_country = '';

    public function mount(Customer $customer): void
    {
        $this->authorize('view', $customer);
        $this->customer = $customer->load(['identifications', 'contacts', 'addresses', 'activities.user', 'updater', 'creator']);
        $this->fillFromCustomer();
        $this->clearSensitiveFormValues();
    }

    #[On('employment-updated')]
    #[On('bank-account-updated')]
    #[On('financial-updated')]
    #[On('investment-updated')]
    #[On('tax-updated')]
    public function refreshEmploymentActivity(): void
    {
        $this->customer->load('activities.user');
    }

    public function editSection(string $section): void
    {
        $this->authorize('update', $this->customer);
        $this->fillFromCustomer();
        if ($section === 'personal') {
            $this->applyNricSuggestions();
        } else {
            $this->clearSensitiveFormValues();
        }
        $this->editingSection = $section;
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->editingSection = null;
        $this->fillFromCustomer();
        $this->clearSensitiveFormValues();
        $this->resetValidation();
    }

    public function updatedIdentificationType(): void
    {
        if ($this->editingSection === 'personal') {
            $this->applyNricSuggestions();
        }
    }

    public function updatedIdentificationNumber(): void
    {
        if ($this->editingSection === 'personal') {
            $this->applyNricSuggestions();
        }
    }

    public function updatedGender(): void
    {
        $this->suggestSalutation();
    }

    public function updatedMaritalStatus(): void
    {
        $this->suggestSalutation();
    }

    private function suggestSalutation(): void
    {
        $defaultSalutation = Customer::defaultSalutationForGender($this->gender, $this->marital_status);

        if ($defaultSalutation !== null && (blank($this->salutation) || in_array($this->salutation, Customer::AUTOMATIC_SALUTATIONS, true))) {
            $this->salutation = $defaultSalutation;
        }
    }

    public function savePersonal(CustomerActivityLogger $logger, CustomerDuplicateChecker $duplicateChecker): void
    {
        $this->authorize('update', $this->customer);
        $this->applyNricSuggestions();
        $this->resetErrorBag();

        $result = app(CustomerProfileWrites::class)->savePersonal($this->customer, [
            'salutation' => $this->salutation,
            'full_legal_name' => $this->full_legal_name,
            'identification_type' => $this->identification_type,
            'identification_number' => $this->identification_number,
            'issuing_jurisdiction' => $this->issuing_jurisdiction,
            'old_nric' => $this->old_nric,
            'date_of_birth' => $this->date_of_birth,
            'gender' => $this->gender,
            'nationality' => $this->nationality,
            'country_of_residence' => $this->country_of_residence,
            'race' => $this->race,
            'marital_status' => $this->marital_status,
        ], auth()->user(), $this->confirmWeakDuplicates);
        if ($result['weak'] !== []) {
            $this->weakMatches = $result['weak'];

            return;
        }

        $this->refreshCustomer();
        $this->editingSection = null;
        $this->clearSensitiveFormValues();
        $this->weakMatches = [];
        $this->confirmWeakDuplicates = false;
        $this->notifySuccess('Personal information saved.');
    }

    public function saveContact(CustomerActivityLogger $logger): void
    {
        $this->authorize('update', $this->customer);
        $this->resetErrorBag();

        app(CustomerProfileWrites::class)->saveContact($this->customer, [
            'mobile' => $this->mobile,
            'home_telephone' => $this->home_telephone,
            'email' => $this->email,
            'social' => $this->social,
            'socialAccounts' => $this->socialAccounts,
        ], auth()->user());

        $this->refreshCustomer();
        $this->editingSection = null;
        $this->clearSensitiveFormValues();
        $this->notifySuccess('Contact information saved.');
    }

    public function saveAddress(CustomerActivityLogger $logger): void
    {
        $this->authorize('update', $this->customer);
        $this->resetErrorBag();

        app(CustomerProfileWrites::class)->saveAddress($this->customer, [
            'residential_line_1' => $this->residential_line_1,
            'residential_line_2' => $this->residential_line_2,
            'residential_city' => $this->residential_city,
            'residential_state' => $this->residential_state,
            'residential_postcode' => $this->residential_postcode,
            'residential_country' => $this->residential_country,
            'occupancy_status' => $this->occupancy_status,
            'occupancy_other' => $this->occupancy_other,
            'same_as_residential' => $this->same_as_residential,
            'mailing_line_1' => $this->mailing_line_1,
            'mailing_line_2' => $this->mailing_line_2,
            'mailing_city' => $this->mailing_city,
            'mailing_state' => $this->mailing_state,
            'mailing_postcode' => $this->mailing_postcode,
            'mailing_country' => $this->mailing_country,
        ], auth()->user());

        $this->refreshCustomer();
        $this->editingSection = null;
        $this->clearSensitiveFormValues();
        $this->notifySuccess('Address information saved.');
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->customer);

        DB::transaction(function (): void {
            $relationshipIds = CustomerRelationship::withTrashed()
                ->where('customer_id', $this->customer->id)
                ->orWhere('related_customer_id', $this->customer->id)
                ->pluck('id');

            CustomerPepAssociation::query()->whereIn('customer_relationship_id', $relationshipIds)->delete();
            CustomerRelationship::withTrashed()->whereIn('id', $relationshipIds)->forceDelete();
            $this->customer->delete();
        });

        session()->flash('status', 'Customer deleted.');
        $this->redirect(route('customers.index'), navigate: true);
    }

    public function restore(CustomerActivityLogger $logger): void
    {
        $this->authorize('update', $this->customer);

        if (! $this->customer->isArchived()) {
            return;
        }

        $this->customer->update([
            'status' => CustomerStatus::Active,
            'updated_by' => auth()->id(),
        ]);

        $logger->log($this->customer, CustomerActivityAction::Restored, auth()->user());

        $this->refreshCustomer();
        $this->notifySuccess('Customer restored.');
    }

    protected function fillFromCustomer(): void
    {
        $identification = $this->customer->identifications->firstWhere('is_primary', true)
            ?? $this->customer->identifications->first();
        $residential = $this->customer->addresses->firstWhere('type', AddressType::Residential);
        $mailing = $this->customer->addresses->firstWhere('type', AddressType::Mailing);

        $this->salutation = $this->customer->salutation ?? '';
        $this->full_legal_name = $this->customer->full_legal_name ?? '';
        $this->identification_type = $identification?->type?->value
            ?? $this->customer->primary_identification_type?->value
            ?? '';
        $this->identification_number = $identification?->number
            ?? $this->customer->primary_identification_number
            ?? '';
        if (blank($this->identification_number)) {
            $this->identification_type = '';
        }
        $this->issuing_jurisdiction = $identification?->issuing_jurisdiction === 'UNSPECIFIED' ? '' : ($identification?->issuing_jurisdiction ?? '');
        $this->old_nric = $identification?->old_nric ?? '';
        $this->date_of_birth = $this->customer->date_of_birth?->format('Y-m-d') ?? '';
        $this->gender = $this->listedChoice($this->customer->gender, ['Male', 'Female', 'Other']);
        $this->nationality = $this->customer->nationality ?? '';
        $this->country_of_residence = $this->customer->country_of_residence ?: 'Malaysia';
        $this->race = $this->customer->race ?? '';
        $this->marital_status = $this->listedChoice($this->customer->marital_status, ['Single', 'Married', 'Divorced', 'Widowed']);

        $this->mobile = $this->customer->contacts->firstWhere('type', ContactType::Mobile)?->value
            ?? $this->customer->primary_mobile
            ?? '';
        $this->home_telephone = $this->customer->contacts->firstWhere('type', ContactType::Home)?->value ?? '';
        $this->email = $this->customer->contacts->firstWhere('type', ContactType::Email)?->value
            ?? $this->customer->primary_email
            ?? '';
        $this->social = $this->customer->contacts->first(fn (CustomerContact $contact): bool => $contact->type === ContactType::Social && $contact->platform === null)?->value ?? '';
        foreach (['facebook', 'instagram', 'twitter'] as $platform) {
            $this->socialAccounts[$platform] = $this->customer->contacts->first(fn (CustomerContact $contact): bool => $contact->type === ContactType::Social && $contact->platform === $platform)?->value ?? '';
        }

        $this->residential_line_1 = $residential?->line_1 ?? '';
        $this->residential_line_2 = $residential?->line_2 ?? '';
        $this->residential_city = $residential?->city ?? '';
        $this->residential_state = $residential?->state ?? '';
        $this->residential_postcode = $residential?->postcode ?? '';
        $this->residential_country = $residential?->country ?? '';
        $this->occupancy_status = $residential?->occupancy_status?->value ?? '';
        $this->occupancy_other = $residential?->occupancy_other ?? '';

        $this->same_as_residential = $mailing?->same_as_residential ?? true;
        $this->mailing_line_1 = $mailing?->line_1 ?? '';
        $this->mailing_line_2 = $mailing?->line_2 ?? '';
        $this->mailing_city = $mailing?->city ?? '';
        $this->mailing_state = $mailing?->state ?? '';
        $this->mailing_postcode = $mailing?->postcode ?? '';
        $this->mailing_country = $mailing?->country ?? '';
    }

    protected function refreshCustomer(): void
    {
        $this->customer = $this->customer->fresh(['identifications', 'contacts', 'addresses', 'activities.user', 'updater', 'creator']);
        $this->fillFromCustomer();
        if ($this->editingSection !== 'personal') {
            $this->clearSensitiveFormValues();
        }
    }

    /** @param  list<string>  $choices */
    private function listedChoice(?string $value, array $choices): string
    {
        foreach ($choices as $choice) {
            if (strcasecmp((string) $value, $choice) === 0) {
                return $choice;
            }
        }

        return $value ?? '';
    }

    protected function clearSensitiveFormValues(): void
    {
        $this->identification_number = '';
        $this->old_nric = '';
    }

    #[On('relationship-updated')]
    #[On('compliance-updated')]
    public function refreshRelationshipActivity(): void
    {
        $this->authorize('view', $this->customer);
        $this->refreshCustomer();
    }

    protected function applyNricSuggestions(): void
    {
        if ($this->identification_type !== IdentificationType::Nric->value) {
            return;
        }

        $parsed = app(NricParser::class)->parse($this->identification_number);

        if ($parsed === null) {
            return;
        }

        if (blank($this->date_of_birth)) {
            $this->date_of_birth = $parsed['date_of_birth'];
        }

        if (blank($this->gender)) {
            $this->gender = $parsed['gender'];
            $this->updatedGender();
        }

        if (blank($this->nationality)) {
            $this->nationality = $parsed['nationality'];
        }
    }

    public function render(): View
    {
        return view('livewire.customers.show')->layout('layouts.app');
    }
}
