<?php

namespace App\Livewire\Customers;

use App\Livewire\Concerns\NotifiesBrowser;
use App\Models\Customer;
use App\Models\CustomerComplianceDeclaration;
use App\Models\CustomerPepAssociation;
use App\Services\CustomerActivityLogger;
use App\Services\CustomerProfileWrites;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class Compliance extends Component
{
    use AuthorizesRequests;
    use NotifiesBrowser;

    public Customer $customer;

    public string $editingKind = '';

    /** @var array<string, string> */
    public array $declaration = [];

    public ?int $selectedAssociationId = null;

    public string $associationMode = '';

    /** @var array<string, string> */
    public array $association = [];

    public string $endDate = '';

    public function mount(Customer $customer): void
    {
        $this->authorize('view', $customer);
        $this->customer = $customer;
    }

    public function editDeclaration(string $kind): void
    {
        $this->authorize('update', $this->customer);
        abort_unless(in_array($kind, ['pep', 'bankruptcy', 'petition'], true), 404);
        $this->cancel();
        $current = $this->currentDeclaration($kind);
        $this->editingKind = $kind;
        $this->declaration = [
            'answer' => $current?->answer ?? 'not_declared',
            'remarks' => $current?->remarks ?? '',
            'declared_on' => $current?->declared_on?->toDateString() ?? now()->toDateString(),
            'source' => $current?->source ?? 'staff_recorded',
            'confirmation_status' => $current?->confirmation_status ?? 'unconfirmed',
        ];
    }

    public function saveDeclaration(CustomerActivityLogger $logger): void
    {
        $this->authorize('update', $this->customer);
        abort_unless(in_array($this->editingKind, ['pep', 'bankruptcy', 'petition'], true), 404);
        app(CustomerProfileWrites::class)->saveComplianceDeclaration($this->customer, $this->editingKind, $this->declaration, auth()->user());
        $this->cancel();
        $this->dispatch('compliance-updated');
        $this->notifySuccess('Declaration saved.');
    }

    public function addAssociation(): void
    {
        $this->authorize('update', $this->customer);
        $this->cancel();
        $this->associationMode = 'edit';
        $this->association = ['source' => 'new', 'name' => '', 'relationship' => '', 'identification_type' => '', 'identification_number' => '', 'occupation' => '', 'position' => '', 'position_meaning' => 'public_position', 'organization' => '', 'country' => '', 'position_status' => 'current', 'remarks' => '', 'started_on' => now()->toDateString()];
    }

    public function viewAssociation(int $id): void
    {
        $this->authorize('view', $this->customer);
        $this->customer->pepAssociations()->findOrFail($id);
        $this->cancel();
        $this->selectedAssociationId = $id;
        $this->associationMode = 'view';
    }

    public function editAssociation(int $id): void
    {
        $this->authorize('update', $this->customer);
        $record = $this->associationRecord($id);
        $this->cancel();
        $this->selectedAssociationId = $id;
        $this->associationMode = 'edit';
        $this->association = [
            'source' => $record->customer_relationship_id ? 'relationship' : ($record->related_person_id ? 'new' : 'self'),
            'relationship_id' => (string) ($record->customer_relationship_id ?? ''),
            'name' => $record->relatedPerson?->full_legal_name ?? '',
            'relationship' => $record->relationship,
            'identification_type' => $record->relatedPerson?->identification_type?->value ?? '',
            'identification_number' => '',
            'occupation' => $record->occupation ?? '',
            'position' => $record->position ?? '',
            'position_meaning' => $record->position_meaning ?? '',
            'organization' => $record->organization ?? '',
            'country' => $record->country ?? '',
            'position_status' => $record->position_status,
            'remarks' => $record->remarks ?? '',
            'started_on' => $record->started_on->toDateString(),
        ];
    }

    public function saveAssociation(CustomerActivityLogger $logger): void
    {
        $this->authorize('update', $this->customer);
        abort_unless($this->associationMode === 'edit', 404);
        app(CustomerProfileWrites::class)->savePepAssociation($this->customer, $this->association, auth()->user(), $this->selectedAssociationId);
        $this->cancel();
        $this->dispatch('compliance-updated');
        $this->notifySuccess('PEP association saved.');
    }

    public function startEndAssociation(int $id): void
    {
        $this->authorize('update', $this->customer);
        $this->associationRecord($id);
        $this->cancel();
        $this->selectedAssociationId = $id;
        $this->associationMode = 'end';
        $this->endDate = now()->toDateString();
    }

    public function endAssociation(CustomerActivityLogger $logger): void
    {
        $this->authorize('update', $this->customer);
        abort_unless($this->associationMode === 'end' && $this->selectedAssociationId, 404);
        app(CustomerProfileWrites::class)->endPepAssociation($this->customer, (int) $this->selectedAssociationId, $this->endDate, auth()->user());
        $this->cancel();
        $this->dispatch('compliance-updated');
        $this->notifySuccess('PEP association ended.');
    }

    #[On('discard-compliance-edit')]
    public function cancel(): void
    {
        $this->editingKind = '';
        $this->declaration = [];
        $this->selectedAssociationId = null;
        $this->associationMode = '';
        $this->association = [];
        $this->endDate = '';
        $this->resetValidation();
    }

    private function currentDeclaration(string $kind): ?CustomerComplianceDeclaration
    {
        return $this->customer->complianceDeclarations()->where('current_key', "{$this->customer->id}:{$kind}")->first();
    }

    private function associationRecord(int $id): CustomerPepAssociation
    {
        return $this->customer->pepAssociations()->whereNull('ended_on')->whereNull('superseded_at')->with(['relatedPerson', 'customerRelationship.relatedPerson', 'customerRelationship.relatedCustomer'])->findOrFail($id);
    }

    public function render(): View
    {
        $this->authorize('view', $this->customer);
        $declarations = $this->customer->complianceDeclarations()->whereNotNull('current_key')->with('recorder')->get()->keyBy('kind');
        $declarationHistory = $this->customer->complianceDeclarations()->whereNull('current_key')->orderByDesc('id')->get()->groupBy('kind');
        $associations = $this->customer->pepAssociations()->with(['relatedPerson', 'customerRelationship.relatedPerson', 'customerRelationship.relatedCustomer', 'customer'])->latest()->get();
        $relationships = $this->customer->relationships()->with(['relatedPerson', 'relatedCustomer'])->get();
        $selected = $this->selectedAssociationId ? $associations->firstWhere('id', $this->selectedAssociationId) : null;

        return view('livewire.customers.compliance', compact('declarations', 'declarationHistory', 'associations', 'relationships', 'selected'));
    }
}
