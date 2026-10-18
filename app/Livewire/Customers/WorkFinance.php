<?php

namespace App\Livewire\Customers;

use App\Livewire\Concerns\NotifiesBrowser;
use App\Models\Customer;
use App\Models\CustomerEmployment;
use App\Services\CustomerActivityLogger;
use App\Services\CustomerProfileWrites;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class WorkFinance extends Component
{
    use AuthorizesRequests;
    use NotifiesBrowser;

    public Customer $customer;

    public string $mode = '';

    public ?int $selectedId = null;

    public string $employmentStatus = '';

    public string $employmentStatusOther = '';

    public string $retirementDate = '';

    /** @var array<string, mixed> */
    public array $form = [];

    public bool $confirmPrimaryReplacement = false;

    public bool $isCorrection = false;

    public function mount(Customer $customer): void
    {
        $this->authorize('view', $customer);
        $this->customer = $customer;
        $this->fillStatus();
        $this->resetForm();
    }

    public function editStatus(): void
    {
        $this->authorize('update', $this->customer);
        $this->fillStatus();
        $this->mode = 'status';
    }

    public function saveStatus(CustomerActivityLogger $logger): void
    {
        $this->authorize('update', $this->customer);
        $this->resetErrorBag();
        app(CustomerProfileWrites::class)->saveEmploymentStatus($this->customer, [
            'employmentStatus' => $this->employmentStatus,
            'employmentStatusOther' => $this->employmentStatusOther,
            'retirementDate' => $this->retirementDate,
        ], auth()->user());

        $this->mode = '';
        $this->dispatch('employment-updated');
        $this->notifySuccess('Employment status saved.');
    }

    public function add(): void
    {
        $this->authorize('update', $this->customer);
        $this->resetForm();
        $this->mode = 'edit';
    }

    public function updatedFormIsCurrent(): void
    {
        if ($this->form['is_current']) {
            $this->form['end_date'] = '';
        } else {
            $this->form['is_primary'] = false;
        }
    }

    public function view(int $id): void
    {
        $this->authorize('view', $this->customer);
        $this->employment($id);
        $this->selectedId = $id;
        $this->mode = 'view';
    }

    public function edit(int $id): void
    {
        $this->authorize('update', $this->customer);
        $employment = $this->employment($id);
        $this->resetForm();
        $this->selectedId = $id;
        foreach (array_keys($this->form) as $field) {
            if (in_array($field, ['is_current', 'is_primary'], true)) {
                $this->form[$field] = (bool) $employment->{$field};
            } elseif (in_array($field, ['start_date', 'end_date'], true)) {
                $this->form[$field] = $employment->{$field}?->format('Y-m-d') ?? '';
            } else {
                $this->form[$field] = $employment->{$field} ?? '';
            }
        }
        $this->mode = 'edit';
    }

    #[On('discard-employment-edit')]
    public function cancel(): void
    {
        $this->resetForm();
        $this->fillStatus();
        $this->mode = '';
        $this->resetValidation();
    }

    public function save(CustomerActivityLogger $logger): void
    {
        $this->authorize('update', $this->customer);
        app(CustomerProfileWrites::class)->saveEmployment($this->customer, $this->form, auth()->user(), $this->selectedId, $this->confirmPrimaryReplacement, $this->isCorrection);
        $this->cancel();
        $this->dispatch('employment-updated');
        $this->notifySuccess('Employment information saved.');
    }

    private function employment(int $id): CustomerEmployment
    {
        return $this->customer->employments()->findOrFail($id);
    }

    private function fillStatus(): void
    {
        $this->customer->refresh();
        $this->employmentStatus = $this->customer->employment_status ?? '';
        $this->employmentStatusOther = $this->customer->employment_status_other ?? '';
        $this->retirementDate = $this->customer->retirement_date?->format('Y-m-d') ?? '';
    }

    private function resetForm(): void
    {
        $this->selectedId = null;
        $this->confirmPrimaryReplacement = false;
        $this->isCorrection = false;
        $this->form = array_fill_keys([
            'arrangement', 'occupation', 'employer_name', 'registration_number', 'industry', 'sub_industry',
            'start_date', 'end_date', 'general_telephone', 'direct_telephone', 'fax', 'address_line_1',
            'address_line_2', 'city', 'state', 'postcode', 'country',
        ], '');
        $this->form['is_current'] = true;
        $this->form['is_primary'] = false;
    }

    public function render(): View
    {
        $this->authorize('view', $this->customer);

        return view('livewire.customers.work-finance', [
            'employments' => $this->customer->employments()->orderByDesc('is_current')->orderByDesc('is_primary')->orderByDesc('start_date')->get(),
            'selected' => $this->selectedId ? $this->customer->employments()->find($this->selectedId) : null,
            'currentPrimary' => $this->customer->employments()->where('is_primary', true)->first(),
        ]);
    }
}
