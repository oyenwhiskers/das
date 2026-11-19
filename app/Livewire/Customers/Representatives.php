<?php

namespace App\Livewire\Customers;

use App\Enums\CustomerActivityAction;
use App\Enums\CustomerType;
use App\Enums\RepresentativeRole;
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

class Representatives extends Component
{
    use AuthorizesRequests;
    use NotifiesBrowser;

    public Customer $customer;

    public ?int $selectedId = null;

    public string $mode = '';

    public string $representativeRole = '';

    public string $signatoryOrder = '';

    public string $fullLegalName = '';

    public string $identificationType = '';

    public string $identificationNumber = '';

    public string $mobile = '';

    public string $homeTelephone = '';

    public string $officeExtension = '';

    public string $email = '';

    public string $occupation = '';

    public function mount(Customer $customer): void
    {
        abort_unless($customer->type === CustomerType::Corporate, 404);
        $this->authorize('view', $customer);
        $this->customer = $customer;
    }

    public function add(string $role, string $order = ''): void
    {
        $this->authorize('update', $this->customer);
        $this->resetForm();
        $this->representativeRole = $role;
        $this->signatoryOrder = $order;
        $this->mode = 'edit';
    }

    public function edit(int $id): void
    {
        $this->authorize('update', $this->customer);
        $record = $this->relationshipRecord($id);
        $person = $record->relatedPerson;
        $this->resetForm();
        $this->selectedId = $id;
        $this->representativeRole = $record->representative_role?->value ?? '';
        $this->signatoryOrder = $record->signatory_order ? (string) $record->signatory_order : '';
        $this->fullLegalName = $person?->full_legal_name ?? '';
        $this->identificationType = $person?->identification_type?->value ?? '';
        $this->identificationNumber = $person?->identification_number ?? '';
        $this->mobile = $person?->mobile ?? '';
        $this->homeTelephone = $person?->home_telephone ?? '';
        $this->officeExtension = $person?->office_extension ?? '';
        $this->email = $person?->email ?? '';
        $this->occupation = $person?->occupation ?? '';
        $this->mode = 'edit';
    }

    #[On('discard-representatives-edit')]
    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(): void
    {
        $this->authorize('update', $this->customer);
        $record = $this->selectedId ? $this->relationshipRecord($this->selectedId) : null;
        app(CustomerProfileWrites::class)->saveRepresentative($this->customer, [
            'representativeRole' => $this->representativeRole,
            'signatoryOrder' => $this->signatoryOrder,
            'fullLegalName' => $this->fullLegalName,
            'identificationType' => $this->identificationType,
            'identificationNumber' => $this->identificationNumber,
            'mobile' => $this->mobile,
            'homeTelephone' => $this->homeTelephone,
            'officeExtension' => $this->officeExtension,
            'email' => $this->email,
            'occupation' => $this->occupation,
        ], auth()->user(), $record);

        $this->resetForm();
        $this->notifySuccess('Representative saved.');
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
        $this->notifySuccess('Representative removed.');
        $this->dispatch('relationship-updated');
    }

    protected function relationshipRecord(int $id): CustomerRelationship
    {
        return $this->customer->relationships()
            ->whereNotNull('representative_role')
            ->with('relatedPerson')
            ->findOrFail($id);
    }

    protected function resetForm(): void
    {
        $this->resetExcept('customer');
        $this->resetValidation();
    }

    public function render(): View
    {
        $this->authorize('view', $this->customer);
        $representatives = $this->customer->relationships()
            ->whereNotNull('representative_role')
            ->with('relatedPerson')
            ->orderByRaw("CASE representative_role WHEN 'primary_contact' THEN 0 ELSE 1 END")
            ->orderBy('signatory_order')
            ->get();

        return view('livewire.customers.representatives', [
            'primaryContact' => $representatives->first(fn (CustomerRelationship $row): bool => $row->representative_role === RepresentativeRole::PrimaryContact),
            'signatory1' => $representatives->first(fn (CustomerRelationship $row): bool => $row->representative_role === RepresentativeRole::AuthorizedSignatory && (int) $row->signatory_order === 1),
            'signatory2' => $representatives->first(fn (CustomerRelationship $row): bool => $row->representative_role === RepresentativeRole::AuthorizedSignatory && (int) $row->signatory_order === 2),
        ]);
    }
}
