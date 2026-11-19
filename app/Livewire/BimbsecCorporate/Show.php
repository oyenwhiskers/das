<?php

namespace App\Livewire\BimbsecCorporate;

use App\Livewire\Concerns\NotifiesBrowser;
use App\Models\BimbsecApplication;
use App\Models\Customer;
use App\Services\Bimbsec\CorporateGenerator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('BIMBSEC Corporate application — DAS')]
class Show extends Component
{
    use AuthorizesRequests;
    use NotifiesBrowser;

    public Customer $customer;

    public BimbsecApplication $application;

    public bool $fromProfile = false;

    public string $account_type = 'trading';

    public string $margin_credit_limit = '';

    public string $verification_mode = '';

    public string $online_trading = 'no';

    public string $estatement = 'email';

    public string $payment = 'epayment';

    public string $bank_account_id = '';

    public string $company_name = '';

    public string $business_registration_no = '';

    /** @var list<array{level: string, label: string, message: string, field_id: ?string}> */
    public array $warnings = [];

    public bool $blocked = false;

    public ?string $previewUrl = null;

    public function mount(Customer $customer, BimbsecApplication $application, CorporateGenerator $generator): void
    {
        abort_unless($application->customer_id === $customer->id, 404);
        abort_unless($customer->isCorporate() && $application->isCorporate(), 404);
        $this->authorize('view', $application);
        $this->customer = $customer;
        $this->application = $application;
        $this->fromProfile = request('from') === 'profile';
        $this->fillChoices();
        $this->company_name = $customer->full_legal_name ?? '';
        $this->business_registration_no = $customer->primary_identification_number ?? '';
        $this->refreshAssessment($generator);
    }

    public function saveForm(CorporateGenerator $generator): void
    {
        $this->authorize('update', $this->customer);
        $choices = BimbsecApplication::normalizeChoices([
            'account_type' => $this->account_type,
            'margin_credit_limit' => $this->margin_credit_limit,
            'verification_mode' => $this->verification_mode !== '' ? $this->verification_mode : null,
            'online_trading' => $this->online_trading,
            'estatement' => $this->estatement,
            'payment' => $this->payment,
            'bank_account_id' => $this->bank_account_id !== '' ? $this->bank_account_id : null,
        ]);
        $this->application->update([
            'choices' => $choices,
            'updated_by' => auth()->id(),
        ]);
        $name = trim($this->company_name);
        $brn = trim($this->business_registration_no);
        if ($name !== '' && $name !== ($this->customer->full_legal_name ?? '')) {
            $this->customer->update([
                'full_legal_name' => $name,
                'updated_by' => auth()->id(),
            ]);
        }
        if ($brn !== ($this->customer->primary_identification_number ?? '')) {
            $this->customer->update([
                'primary_identification_number' => $brn !== '' ? $brn : null,
                'updated_by' => auth()->id(),
            ]);
            $identification = $this->customer->primaryIdentification;
            if ($identification !== null) {
                $identification->update([
                    'number' => $brn !== '' ? $brn : $identification->number,
                    'normalized_number' => $brn !== '' ? preg_replace('/\D+/', '', $brn) : $identification->normalized_number,
                ]);
            }
        }
        $this->customer->refresh();
        $generator->generate($this->application->fresh(), auth()->user());
        $this->application->refresh();
        $this->refreshAssessment($generator);
        $this->notifySuccess('Corporate BIMBSEC form saved.');
    }

    public function regenerate(CorporateGenerator $generator): void
    {
        $this->authorize('update', $this->customer);
        $generator->generate($this->application, auth()->user());
        $this->application->refresh();
        $this->refreshAssessment($generator);
        $this->notifySuccess('PDF regenerated.');
    }

    public function render(): View
    {
        $this->authorize('view', $this->application);
        $this->customer->loadMissing(['bankAccounts']);

        return view('livewire.bimbsec-corporate.show', [
            'banks' => $this->customer->bankAccounts->where('is_active', true)->values(),
            'document' => $this->application->document,
        ])->layout('layouts.app');
    }

    protected function fillChoices(): void
    {
        $choices = BimbsecApplication::normalizeChoices($this->application->choices ?? []);
        $this->account_type = $choices['account_type'] ?? 'trading';
        $this->margin_credit_limit = (string) ($choices['margin_credit_limit'] ?? '');
        $this->verification_mode = (string) ($choices['verification_mode'] ?? '');
        $this->online_trading = $choices['online_trading'] ?? 'no';
        $this->estatement = $choices['estatement'] ?? 'email';
        $this->payment = $choices['payment'] ?? 'epayment';
        $bank = $choices['bank_account_id'] ?? null;
        $this->bank_account_id = $bank === null ? '' : (string) $bank;
    }

    protected function refreshAssessment(CorporateGenerator $generator): void
    {
        $assessment = $generator->assess($this->customer->fresh(), $this->application->choices ?? []);
        $this->warnings = $assessment['warnings'];
        $this->blocked = $assessment['blocked'];
        $document = $this->application->document;
        $this->previewUrl = $document !== null && Storage::disk('local')->exists($document->storage_path)
            ? route('bimbsec-corporate.file', [$this->customer, $this->application])
            : null;
    }
}
