<?php

namespace App\Livewire\Customers;

use App\Enums\CustomerActivityAction;
use App\Enums\CustomerStatus;
use App\Livewire\Concerns\NotifiesBrowser;
use App\Models\Customer;
use App\Models\CustomerEmployment;
use App\Services\CustomerActivityLogger;
use App\Services\EmploymentIndustryLookup;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;
use Throwable;

#[Title('Customers — DAS')]
class Index extends Component
{
    use AuthorizesRequests;
    use NotifiesBrowser;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $sort = 'name_asc';

    /** @var list<int> */
    public array $selected = [];

    /** @var list<int> */
    public array $industryQueue = [];

    public int $industryDone = 0;

    public int $industryTotal = 0;

    public int $industryFilled = 0;

    public int $industrySkipped = 0;

    public int $industryFailed = 0;

    /** @var list<array{name: string, status: string, detail: string}> */
    public array $industryResults = [];

    public function updatingSearch(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    public function updatingSort(): void
    {
        $this->resetPage();
    }

    public function sortByName(): void
    {
        $this->sort = $this->sort === 'name_asc' ? 'name_desc' : 'name_asc';
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage();
        $this->selected = [];
    }

    public function updatedSelected(): void
    {
        $this->normalizeSelected();
    }

    public function toggle(int $customerId): void
    {
        $this->normalizeSelected();
        $this->selected = in_array($customerId, $this->selected, true)
            ? array_values(array_filter($this->selected, fn (int $id): bool => $id !== $customerId))
            : [...$this->selected, $customerId];
    }

    public function toggleAll(): void
    {
        $this->normalizeSelected();
        $ids = $this->filteredQuery()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->selected = $ids !== [] && count(array_diff($ids, $this->selected)) === 0 ? [] : $ids;
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    private function normalizeSelected(): void
    {
        $this->selected = array_values(array_unique(array_map(intval(...), $this->selected)));
    }

    public function deleteSelected(): void
    {
        $customers = Customer::query()->whereIn('id', $this->selected)->get();
        foreach ($customers as $customer) {
            $this->authorize('delete', $customer);
        }

        DB::transaction(function () use ($customers): void {
            foreach ($customers as $customer) {
                $customer->deleteWithDependents();
            }
        });

        $count = $customers->count();
        $this->selected = [];
        $this->resetPage();

        if ($count > 0) {
            $this->notifySuccess($count === 1 ? 'Customer deleted.' : $count.' customers deleted.');
        }
    }

    /** @return array{ok: bool, pending?: bool, done?: int, total?: int, percent?: int, label?: string, message?: string, type?: string} */
    public function beginIndustrySuggest(): array
    {
        $this->normalizeSelected();
        if ($this->selected === []) {
            return ['ok' => false, 'message' => 'Select at least one customer.'];
        }

        $this->industryQueue = array_values($this->selected);
        $this->industryTotal = count($this->industryQueue);
        $this->industryDone = 0;
        $this->industryFilled = 0;
        $this->industrySkipped = 0;
        $this->industryFailed = 0;
        $this->industryResults = [];

        return [
            'ok' => true,
            'pending' => true,
            'done' => 0,
            'total' => $this->industryTotal,
            'percent' => 0,
            'label' => 'Starting '.$this->industryTotal.' '.($this->industryTotal === 1 ? 'customer' : 'customers').'…',
        ];
    }

    /** @return array{ok: bool, pending?: bool, done?: int, total?: int, percent?: int, label?: string, message?: string, type?: string} */
    public function advanceIndustrySuggest(EmploymentIndustryLookup $lookup): array
    {
        if ($this->industryQueue === []) {
            return $this->industrySuggestFinished();
        }

        $customerId = array_shift($this->industryQueue);
        $this->industryDone++;
        $customer = Customer::query()
            ->with(['employments', 'residentialAddress'])
            ->find($customerId);

        if ($customer === null) {
            $this->industrySkipped++;
            $this->recordIndustryResult('Unknown customer', 'skipped', 'Customer not found');
        } else {
            try {
                $this->authorize('update', $customer);
                $this->suggestIndustryForCustomer($customer, $lookup);
            } catch (AuthorizationException) {
                $this->industryFailed++;
                $this->recordIndustryResult($customer->full_legal_name, 'failed', 'Not allowed to update');
            } catch (Throwable $exception) {
                report($exception);
                $this->industryFailed++;
                $this->recordIndustryResult($customer->full_legal_name, 'failed', 'Unexpected error');
            }
        }

        if ($this->industryQueue !== []) {
            $name = $customer?->full_legal_name;
            $label = 'Checked '.$this->industryDone.' of '.$this->industryTotal.($name ? ' — '.$name : '');

            return [
                'ok' => true,
                'pending' => true,
                'done' => $this->industryDone,
                'total' => $this->industryTotal,
                'percent' => $this->industryTotal > 0
                    ? (int) floor(100 * $this->industryDone / ($this->industryTotal + 1))
                    : 0,
                'label' => $label,
            ];
        }

        return $this->industrySuggestFinished();
    }

    /** Keep tests / callers that expect one shot; UI uses begin + advance with a progress modal. */
    public function suggestIndustrySelected(EmploymentIndustryLookup $lookup): void
    {
        $start = $this->beginIndustrySuggest();
        if (! ($start['ok'] ?? false)) {
            $this->notifyError($start['message'] ?? 'Select at least one customer.');

            return;
        }

        do {
            $result = $this->advanceIndustrySuggest($lookup);
        } while ($result['pending'] ?? false);

        if (($result['type'] ?? 'success') === 'error') {
            $this->notifyError($result['message'] ?? 'Could not fill industry.');

            return;
        }
        $this->notifySuccess($result['message'] ?? 'Done.');
    }

    private function suggestIndustryForCustomer(Customer $customer, EmploymentIndustryLookup $lookup): void
    {
        $name = $customer->full_legal_name ?: 'Customer #'.$customer->id;
        $needsFill = fn (CustomerEmployment $row): bool => blank($row->industry) || blank($row->sub_industry);
        $jobs = $customer->employments
            ->filter($needsFill)
            ->sortBy([
                fn (CustomerEmployment $row): int => $row->is_primary && $row->is_current ? 0 : ($row->is_current ? 1 : 2),
                fn (CustomerEmployment $row): int => (int) $row->id,
            ])
            ->values();

        if ($jobs->isEmpty()) {
            $this->industrySkipped++;
            $this->recordIndustryResult($name, 'skipped', 'Already has industry and sub-industry, or no employment');

            return;
        }

        $filledLabels = [];
        $failedAny = false;
        $profile = $this->industrySearchProfile($customer);

        foreach ($jobs as $job) {
            $employment = [
                'employer_name' => $job->employer_name ?? '',
                'occupation' => $job->occupation ?? '',
                'arrangement' => $job->arrangement ?? '',
                'registration_number' => $job->registration_number ?? '',
                'city' => $job->city ?? '',
                'state' => $job->state ?? '',
                'country' => $job->country ?? '',
                'industry' => $job->industry ?? '',
                'sub_industry' => $job->sub_industry ?? '',
            ];

            try {
                $result = $lookup->suggest($employment, $profile);
            } catch (RuntimeException) {
                $failedAny = true;

                continue;
            } catch (Throwable $exception) {
                report($exception);
                $failedAny = true;

                continue;
            }

            if ($result['industry'] === null || $result['sub_industry'] === null) {
                $failedAny = true;

                continue;
            }

            $job->update([
                'industry' => $result['industry'],
                'sub_industry' => $result['sub_industry'],
            ]);
            $filledLabels[] = $result['industry'].' / '.$result['sub_industry'];
        }

        if ($filledLabels !== []) {
            $this->industryFilled++;
            $this->recordIndustryResult($name, 'filled', implode('; ', $filledLabels));
        } elseif ($failedAny) {
            $this->industryFailed++;
            $this->recordIndustryResult($name, 'failed', 'Could not determine industry');
        } else {
            $this->industrySkipped++;
            $this->recordIndustryResult($name, 'skipped', 'No update made');
        }
    }

    private function recordIndustryResult(string $name, string $status, string $detail): void
    {
        $this->industryResults[] = [
            'name' => $name,
            'status' => $status,
            'detail' => $detail,
        ];
    }

    /**
     * @return array{ok: bool, pending: false, done: int, total: int, percent: int, label: string, message: string, type: string, results: list<array{name: string, status: string, detail: string}>}
     */
    private function industrySuggestFinished(): array
    {
        $filled = $this->industryFilled;
        $skipped = $this->industrySkipped;
        $failed = $this->industryFailed;
        if ($filled > 0) {
            $message = "Industry filled for {$filled}, skipped {$skipped}, failed {$failed}.";
            $type = 'success';
        } elseif ($failed > 0) {
            $message = "Could not fill industry ({$failed} failed, {$skipped} skipped).";
            $type = 'error';
        } else {
            $message = $skipped > 0
                ? "No industry updates needed ({$skipped} skipped — already complete or no employment)."
                : 'No industry updates needed.';
            $type = 'success';
        }

        return [
            'ok' => true,
            'pending' => false,
            'done' => $this->industryTotal,
            'total' => $this->industryTotal,
            'percent' => 100,
            'label' => 'Done',
            'message' => $message,
            'type' => $type,
            'results' => $this->industryResults,
        ];
    }

    /**
     * @return array{age?: int, date_of_birth?: string, employment_status?: string, employment_status_other?: string, nationality?: string, country_of_residence?: string, residential?: array<string, string>}
     */
    private function industrySearchProfile(Customer $customer): array
    {
        $profile = [];
        $dob = $customer->date_of_birth;
        if ($dob !== null) {
            $profile['date_of_birth'] = $dob->toDateString();
            $profile['age'] = $dob->age;
        }
        foreach (['employment_status', 'employment_status_other', 'nationality', 'country_of_residence'] as $key) {
            $value = trim((string) ($customer->{$key} ?? ''));
            if ($value !== '') {
                $profile[$key] = $value;
            }
        }
        $address = $customer->residentialAddress;
        if ($address !== null) {
            $residential = [];
            foreach (['city', 'state', 'postcode', 'country'] as $key) {
                $value = trim((string) ($address->{$key} ?? ''));
                if ($value !== '') {
                    $residential[$key] = $value;
                }
            }
            if ($residential !== []) {
                $profile['residential'] = $residential;
            }
        }

        return $profile;
    }

    public function restore(int $customerId, CustomerActivityLogger $logger): void
    {
        $customer = Customer::query()->findOrFail($customerId);
        $this->authorize('update', $customer);

        if (! $customer->isArchived()) {
            return;
        }

        $customer->update([
            'status' => CustomerStatus::Active,
            'updated_by' => auth()->id(),
        ]);

        $logger->log($customer, CustomerActivityAction::Restored, auth()->user());

        $this->notifySuccess('Customer restored.');
    }

    public function render(): View
    {
        $this->authorize('viewAny', Customer::class);

        $hasFilters = filled($this->search) || $this->status !== 'all';

        $customers = $this->filteredQuery()
            ->withProfileCompletion()
            ->when($this->sort === 'name_asc', fn ($query) => $query->orderBy('full_legal_name'))
            ->when($this->sort === 'name_desc', fn ($query) => $query->orderByDesc('full_legal_name'))
            ->when($this->sort === 'name', fn ($query) => $query->orderBy('full_legal_name'))
            ->when($this->sort === 'added', fn ($query) => $query->latest('created_at'))
            ->when($this->sort === 'updated' || $this->sort === '', fn ($query) => $query->latest('updated_at'))
            ->paginate(50);

        return view('livewire.customers.index', [
            'customers' => $customers,
            'hasFilters' => $hasFilters,
            'allSelected' => $customers->total() > 0 && count($this->selected) === $customers->total(),
        ])->layout('layouts.app');
    }

    /** @return Builder<Customer> */
    private function filteredQuery(): Builder
    {
        return Customer::query()
            ->search($this->search)
            ->when($this->status === 'active', fn ($query) => $query->where('status', CustomerStatus::Active))
            ->when($this->status === 'archived', fn ($query) => $query->where('status', CustomerStatus::Archived));
    }
}
