<?php

namespace App\Livewire\Bimbsec;

use App\Livewire\Concerns\NotifiesBrowser;
use App\Models\BimbsecApplication;
use App\Models\BimbsecBatch;
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
        $batch = $this->batchForSelection($runner);
        if ($batch === null) {
            return;
        }
        $this->redirect(route('bimbsec.batches.show', $batch));
    }

    public function downloadAll(BatchRunner $runner): array
    {
        return $this->beginOutput($runner, 'download');
    }

    public function printAll(BatchRunner $runner): array
    {
        return $this->beginOutput($runner, 'print');
    }

    public function batchAdvance(int $batchId, string $action, BatchRunner $runner): array
    {
        $batch = BimbsecBatch::query()->findOrFail($batchId);
        $this->authorize('view', $batch);
        if (in_array($batch->status, ['queued', 'running'], true)) {
            $runner->processNext($batch);
        }

        return $this->outputPayload($batch->refresh(), $action);
    }

    public function batchStatus(int $batchId, string $action): array
    {
        $batch = BimbsecBatch::query()->findOrFail($batchId);
        $this->authorize('view', $batch);

        return $this->outputPayload($batch->refresh(), $action);
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

    public function printCustomer(int $customerId, Generator $generator): array
    {
        $customer = Customer::query()->findOrFail($customerId);
        $this->authorize('view', $customer);
        $application = $this->latest($customer);
        if ($application->document === null) {
            $generator->generate($application, auth()->user());
            $application->refresh();
        }
        $document = $application->document;
        if ($document === null || ! $document->allowsPrint()) {
            return [
                'ok' => false,
                'message' => 'A name, identification number, address, or bank account does not fit the form. Open the PDF and shorten it, then print again.',
            ];
        }

        return [
            'ok' => true,
            'url' => route('bimbsec.print', [$customer, $application]),
        ];
    }

    public function render(Generator $generator): View
    {
        $this->authorize('viewAny', Customer::class);
        $customers = Customer::query()
            ->search($this->search)
            ->with(['bimbsecApplications' => fn ($query) => $query->with('document')->orderBy('id')])
            ->latest('updated_at')
            ->paginate(15);
        $pageIds = $customers->getCollection()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $fit = [];
        foreach ($customers as $customer) {
            $application = $customer->bimbsecApplications->first(fn ($row) => $row->document !== null) ?? $customer->bimbsecApplications->first();
            $assessment = $generator->assess($customer, $application?->choices ?? []);
            $fit[$customer->id] = [
                'blocked' => $assessment['blocked'],
                'issues' => collect($assessment['warnings'])
                    ->where('level', 'blocking')
                    ->pluck('message')
                    ->filter(fn ($message): bool => is_string($message) && $message !== '')
                    ->unique()
                    ->values()
                    ->all(),
            ];
        }

        return view('livewire.bimbsec.index', [
            'customers' => $customers,
            'pageIds' => $pageIds,
            'pageSelected' => $pageIds !== [] && collect($pageIds)->every(fn (int $id): bool => in_array($id, $this->selected, true)),
            'hasFilters' => filled($this->search),
            'fit' => $fit,
        ])->layout('layouts.app');
    }

    private function batchForSelection(BatchRunner $runner): ?BimbsecBatch
    {
        return $this->selectionBatch($runner, process: true);
    }

    /** @return array{ok: bool, pending?: bool, url?: string, batchId?: int, message?: string, done?: int, total?: int, percent?: int, label?: string} */
    private function beginOutput(BatchRunner $runner, string $action): array
    {
        $batch = $this->selectionBatch($runner, process: false);
        if ($batch === null) {
            return ['ok' => false, 'message' => 'Select at least one customer.'];
        }

        return $this->outputPayload($batch, $action);
    }

    private function selectionBatch(BatchRunner $runner, bool $process): ?BimbsecBatch
    {
        $this->authorize('viewAny', Customer::class);
        $this->confirming = false;
        if ($this->selected === []) {
            return null;
        }
        $max = max(1, (int) config('bimbsec.max_selection'));
        if (count($this->selected) > $max) {
            $this->notifyError('Select at most '.$max.' customers at a time.');

            return null;
        }

        return $process
            ? $runner->start($this->selected, auth()->user())
            : $runner->open($this->selected, auth()->user());
    }

    /** @return array{ok: bool, pending?: bool, url?: string, batchId?: int, message?: string, done?: int, total?: int, percent?: int, label?: string} */
    private function outputPayload(BimbsecBatch $batch, string $action): array
    {
        $total = count($batch->application_ids ?? []);
        $done = count($batch->summary ?? []);
        if (in_array($batch->status, ['queued', 'running'], true)) {
            $current = collect($batch->summary ?? [])->last()['name'] ?? null;
            $label = $done === 0
                ? 'Starting '.$total.' '.($total === 1 ? 'form' : 'forms').'…'
                : 'Prepared '.$done.' of '.$total.($current ? ' — '.$current : '');

            return [
                'ok' => true,
                'pending' => true,
                'batchId' => $batch->id,
                'done' => $done,
                'total' => $total,
                'percent' => $total > 0 ? (int) floor(100 * $done / ($total + 1)) : 0,
                'label' => $label,
            ];
        }
        if ($batch->complete) {
            return [
                'ok' => true,
                'pending' => false,
                'batchId' => $batch->id,
                'done' => $total,
                'total' => $total,
                'percent' => 100,
                'label' => 'Ready',
                'url' => $action === 'download'
                    ? route('bimbsec.batches.file', [$batch, 'download' => 1])
                    : route('bimbsec.batches.print', $batch),
            ];
        }
        $names = collect($batch->summary ?? [])->where('ok', false)->pluck('name')->filter()->implode(', ');

        return [
            'ok' => false,
            'done' => $done,
            'total' => $total,
            'percent' => 100,
            'message' => $names !== ''
                ? 'These forms need a shorter name, identification number, address, or bank account: '.$names.'.'
                : 'The selected PDFs could not be prepared.',
        ];
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
