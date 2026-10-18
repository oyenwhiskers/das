<?php

namespace App\Livewire\Bimbsec;

use App\Livewire\Concerns\NotifiesBrowser;
use App\Models\BimbsecApplication;
use App\Models\Customer;
use App\Services\Bimbsec\BatchRunner;
use App\Services\Bimbsec\Generator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('BIMBSEC — DAS')]
class Index extends Component
{
    use AuthorizesRequests;
    use NotifiesBrowser;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    /** @var list<int> */
    public array $selected = [];

    public bool $confirming = false;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    public function toggle(int $customerId): void
    {
        if (in_array($customerId, $this->selected, true)) {
            $this->selected = array_values(array_filter($this->selected, fn (int $id): bool => $id !== $customerId));

            return;
        }
        $this->selected[] = $customerId;
    }

    /** @param  list<int>  $ids */
    public function togglePage(array $ids): void
    {
        $ids = array_map(intval(...), $ids);
        $all = $ids !== [] && collect($ids)->every(fn (int $id): bool => in_array($id, $this->selected, true));
        $this->selected = $all
            ? array_values(array_filter($this->selected, fn (int $id): bool => ! in_array($id, $ids, true)))
            : array_values(array_unique([...$this->selected, ...$ids]));
    }

    public function selectMatching(): void
    {
        $max = max(1, (int) config('bimbsec.max_selection'));
        $ids = Customer::query()->search($this->search)->latest('updated_at')->limit($max + 1)->pluck('id');
        if ($ids->count() > $max) {
            $this->notifyError('More than '.$max.' customers match. Narrow the search before selecting all.');

            return;
        }
        $this->selected = $ids->map(fn ($id): int => (int) $id)->all();
        $this->confirming = false;
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->confirming = false;
    }

    public function askGenerate(): void
    {
        $max = max(1, (int) config('bimbsec.max_selection'));
        if ($this->selected === []) {
            return;
        }
        if (count($this->selected) > $max) {
            $this->notifyError('Select at most '.$max.' customers at a time.');

            return;
        }
        $this->confirming = true;
    }

    public function cancelGenerate(): void
    {
        $this->confirming = false;
    }

    public function generateSelected(BatchRunner $runner): void
    {
        $this->authorize('viewAny', Customer::class);
        $this->confirming = false;
        $batch = $runner->start($this->selected, auth()->user());
        $this->redirect(route('bimbsec.batches.show', $batch));
    }

    public function openCustomer(int $customerId, Generator $generator): void
    {
        $customer = Customer::query()->findOrFail($customerId);
        $this->authorize('view', $customer);
        $application = $this->latest($customer);
        if ($application->document === null) {
            $generator->generate($application, auth()->user());
        }
        $this->redirect(route('bimbsec.show', [$customer, $application]));
    }

    public function printCustomer(int $customerId): void
    {
        $customer = Customer::query()->findOrFail($customerId);
        $this->authorize('view', $customer);
        $application = $customer->currentBimbsecApplication();
        $document = $application?->document;
        if ($document === null) {
            $this->notifyError('Generate the PDF before printing.');

            return;
        }
        if (! $document->allowsPrint()) {
            $this->redirect(route('bimbsec.show', [$customer, $application]));

            return;
        }
        $this->redirect(route('bimbsec.print', [$customer, $application]));
    }

    public function render(): View
    {
        $this->authorize('viewAny', Customer::class);
        $customers = Customer::query()->search($this->search)->latest('updated_at')->paginate(15);
        $pageIds = $customers->getCollection()->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return view('livewire.bimbsec.index', [
            'customers' => $customers,
            'pageIds' => $pageIds,
            'pageSelected' => $pageIds !== [] && collect($pageIds)->every(fn (int $id): bool => in_array($id, $this->selected, true)),
            'hasFilters' => filled($this->search),
        ])->layout('layouts.app');
    }

    private function latest(Customer $customer): BimbsecApplication
    {
        return $customer->currentBimbsecApplication() ?? $customer->bimbsecApplications()->create([
            'choices' => BimbsecApplication::normalizeChoices([]),
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);
    }
}
