<?php

namespace App\Livewire\Customers;

use App\Enums\CustomerActivityAction;
use App\Livewire\Concerns\NotifiesBrowser;
use App\Models\Customer;
use App\Models\CustomerRelationship;
use App\Services\CustomerActivityLogger;
use App\Services\CustomerProfileWrites;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class FamilyRelationships extends Component
{
    use AuthorizesRequests;
    use NotifiesBrowser;

    public Customer $customer;

    public ?int $selectedId = null;

    public string $mode = '';

    public string $source = 'new';

    public string $search = '';

    public string $linkedCustomerId = '';

    public string $fullLegalName = '';

    public string $relationship = '';

    public string $otherRelationship = '';

    public string $identificationType = '';

    public string $identificationNumber = '';

    public string $dateOfBirth = '';

    public string $gender = '';

    public string $nationality = '';

    public string $mobile = '';

    public string $homeTelephone = '';

    public string $email = '';

    public string $occupation = '';

    public string $employerName = '';

    public string $employerAddress = '';

    public string $employerAddressLine1 = '';

    public string $employerAddressLine2 = '';

    public string $employerCity = '';

    public string $employerState = '';

    public string $employerPostcode = '';

    public string $employerCountry = '';

    public bool $isNextOfKin = false;

    public bool $isPrimaryNextOfKin = false;

    public function mount(Customer $customer): void
    {
        $this->authorize('view', $customer);
        $this->customer = $customer;
    }

    public function add(): void
    {
        $this->authorize('update', $this->customer);
        $this->resetForm();
        $this->mode = 'edit';
    }

    public function view(int $id): void
    {
        $this->authorize('view', $this->customer);
        $this->relationshipRecord($id);
        $this->selectedId = $id;
        $this->mode = 'view';
    }

    public function edit(int $id): void
    {
        $this->authorize('update', $this->customer);
        $record = $this->relationshipRecord($id);
        $person = $record->relatedPerson;
        $this->resetForm();
        $this->selectedId = $id;
        $this->source = $record->related_customer_id ? 'customer' : 'new';
        $this->linkedCustomerId = (string) ($record->related_customer_id ?? '');
        $this->relationship = $record->relationship;
        $this->otherRelationship = $record->other_relationship ?? '';
        $this->isNextOfKin = $record->is_next_of_kin;
        $this->isPrimaryNextOfKin = $record->is_primary_next_of_kin;

        if ($person) {
            $this->fullLegalName = $person->full_legal_name;
            $this->identificationType = $person->identification_type?->value ?? '';
            $this->identificationNumber = $person->identification_number ?? '';
            $this->dateOfBirth = $person->date_of_birth?->format('Y-m-d') ?? '';
            $this->gender = $person->gender ?? '';
            $this->nationality = $person->nationality ?? '';
            $this->mobile = $person->mobile ?? '';
            $this->homeTelephone = $person->home_telephone ?? '';
            $this->email = $person->email ?? '';
            $this->occupation = $person->occupation ?? '';
            $this->employerName = $person->employer_name ?? '';
            $this->employerAddress = $person->employer_address ?? '';
            $this->employerAddressLine1 = $person->employer_address_line_1 ?? '';
            $this->employerAddressLine2 = $person->employer_address_line_2 ?? '';
            $this->employerCity = $person->employer_city ?? '';
            $this->employerState = $person->employer_state ?? '';
            $this->employerPostcode = $person->employer_postcode ?? '';
            $this->employerCountry = $person->employer_country ?? '';
        }

        $this->mode = 'edit';
    }

    #[On('discard-relationship-edit')]
    public function cancel(): void
    {
        $this->resetForm();
    }

    public function updatedIsNextOfKin(): void
    {
        if (! $this->isNextOfKin) {
            $this->isPrimaryNextOfKin = false;
        }
    }

    public function save(CustomerActivityLogger $logger): void
    {
        $this->authorize('update', $this->customer);
        $record = $this->selectedId ? $this->relationshipRecord($this->selectedId) : null;
        $this->source = $record ? ($record->related_customer_id ? 'customer' : 'new') : $this->source;
        app(CustomerProfileWrites::class)->saveRelationship($this->customer, [
            'source' => $this->source,
            'linkedCustomerId' => $this->linkedCustomerId,
            'fullLegalName' => $this->fullLegalName,
            'relationship' => $this->relationship,
            'otherRelationship' => $this->otherRelationship,
            'identificationType' => $this->identificationType,
            'identificationNumber' => $this->identificationNumber,
            'dateOfBirth' => $this->dateOfBirth,
            'gender' => $this->gender,
            'nationality' => $this->nationality,
            'mobile' => $this->mobile,
            'homeTelephone' => $this->homeTelephone,
            'email' => $this->email,
            'occupation' => $this->occupation,
            'employerName' => $this->employerName,
            'employerAddress' => $this->employerAddress,
            'employerAddressLine1' => $this->employerAddressLine1,
            'employerAddressLine2' => $this->employerAddressLine2,
            'employerCity' => $this->employerCity,
            'employerState' => $this->employerState,
            'employerPostcode' => $this->employerPostcode,
            'employerCountry' => $this->employerCountry,
            'isNextOfKin' => $this->isNextOfKin,
            'isPrimaryNextOfKin' => $this->isPrimaryNextOfKin,
        ], auth()->user(), $record);

        $this->resetForm();
        $this->notifySuccess('Relationship saved.');
        $this->dispatch('relationship-updated');
    }

    public function remove(int $id, CustomerActivityLogger $logger): void
    {
        $this->authorize('update', $this->customer);
        $record = $this->relationshipRecord($id);
        DB::transaction(function () use ($record, $logger): void {
            $record->update(['active_identity_key' => null]);
            $record->delete();
            $logger->log($this->customer, CustomerActivityAction::RelationshipRemoved, auth()->user());
        });
        $this->resetForm();
        $this->notifySuccess('Relationship removed.');
        $this->dispatch('relationship-updated');
    }

    protected function relationshipRecord(int $id): CustomerRelationship
    {
        return $this->customer->relationships()->with(['relatedPerson', 'relatedCustomer.contacts'])->findOrFail($id);
    }

    protected function resetForm(): void
    {
        $this->resetExcept('customer');
        $this->resetValidation();
    }

    public function render(): View
    {
        $this->authorize('view', $this->customer);
        $relationships = $this->customer->relationships()->with(['relatedPerson', 'relatedCustomer.contacts'])->latest()->get();
        $selected = $this->selectedId ? $relationships->firstWhere('id', $this->selectedId) : null;
        $matches = $this->mode === 'edit' && $this->source === 'customer' && mb_strlen(trim($this->search)) >= 2
            ? Customer::query()->whereKeyNot($this->customer->id)->search(trim($this->search))->limit(10)->get()
            : collect();

        return view('livewire.customers.family-relationships', compact('relationships', 'selected', 'matches'));
    }
}
