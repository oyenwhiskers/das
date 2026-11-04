<?php

namespace App\Livewire\Wakalah;

use App\Enums\IdentificationType;
use App\Livewire\Concerns\NotifiesBrowser;
use App\Models\Customer;
use App\Models\CustomerIdentification;
use App\Services\CustomerDuplicateChecker;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Wakalah Letter — DAS')]
class Show extends Component
{
    use AuthorizesRequests;
    use NotifiesBrowser;

    public Customer $customer;

    public string $clientNric = '';

    public bool $formatNric = true;

    public string $designation = '';

    public string $witnessName = '';

    public string $witnessNric = '';

    public string $witnessDate = '';

    public int $previewVersion = 0;

    public function mount(Customer $customer): void
    {
        $this->authorize('view', $customer);
        $this->customer = $customer->loadMissing('primaryIdentification');
        $this->fillFromCustomer();
    }

    public function updatedFormatNric(): void
    {
        $this->clientNric = $this->displayNric($this->clientNric);
    }

    public function save(CustomerDuplicateChecker $duplicates): void
    {
        $this->authorize('update', $this->customer);

        $data = $this->validate([
            'clientNric' => ['nullable', 'string', 'max:100', function (string $attribute, mixed $value, \Closure $fail): void {
                $type = $this->customer->primary_identification_type ?? $this->customer->primaryIdentification?->type;
                if ($type === IdentificationType::Nric && filled($value) && ! preg_match('/^\d{12}$/', CustomerIdentification::normalizeNumber((string) $value) ?? '')) {
                    $fail('NRIC must be 12 digits (with or without dashes).');
                }
            }],
            'formatNric' => ['boolean'],
            'designation' => ['nullable', 'string', 'max:120'],
            'witnessName' => ['nullable', 'string', 'max:200'],
            'witnessNric' => ['nullable', 'string', 'max:40'],
            'witnessDate' => ['nullable', 'date'],
        ]);

        $type = $this->customer->primary_identification_type ?? $this->customer->primaryIdentification?->type;
        $formatted = filled($data['clientNric'])
            ? $this->storeNric(trim($data['clientNric']), $type, (bool) $data['formatNric'])
            : null;

        $jurisdiction = $type === IdentificationType::Nric
            ? 'MY'
            : ($this->customer->primaryIdentification?->issuing_jurisdiction ?: 'UNSPECIFIED');

        if ($type && filled($formatted)) {
            $matches = $duplicates->check($type, $formatted, null, null, null, $this->customer->id, $jurisdiction);
            if ($matches['strong']->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'clientNric' => 'This identification already belongs to another customer, including archived customers.',
                ]);
            }
        }

        try {
            DB::transaction(function () use ($data, $formatted, $type, $jurisdiction): void {
                $this->customer->update([
                    'primary_identification_number' => $formatted,
                    'wakalah_format_nric' => (bool) $data['formatNric'],
                    'wakalah_designation' => filled($data['designation']) ? trim($data['designation']) : null,
                    'wakalah_witness_name' => filled($data['witnessName']) ? trim($data['witnessName']) : null,
                    'wakalah_witness_nric' => filled($data['witnessNric']) ? trim($data['witnessNric']) : null,
                    'wakalah_witness_date' => filled($data['witnessDate']) ? $data['witnessDate'] : null,
                    'updated_by' => auth()->id(),
                ]);

                $identification = $this->customer->primaryIdentification;
                if ($identification) {
                    $identification->update([
                        'number' => $formatted,
                        'normalized_number' => CustomerIdentification::normalizeNumber($formatted),
                        'issuing_jurisdiction' => $jurisdiction,
                    ]);
                } elseif (filled($formatted)) {
                    $this->customer->identifications()->create([
                        'type' => $type ?? IdentificationType::Other,
                        'number' => $formatted,
                        'normalized_number' => CustomerIdentification::normalizeNumber($formatted),
                        'issuing_jurisdiction' => $jurisdiction,
                        'is_primary' => true,
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'clientNric' => 'This identification already belongs to another customer, including archived customers.',
            ]);
        }

        $this->customer->refresh()->loadMissing('primaryIdentification');
        $this->fillFromCustomer();
        $this->previewVersion++;
        $this->notifySuccess('Wakalah letter details saved.');
    }

    public function render(): View
    {
        return view('livewire.wakalah.show', [
            'customer' => $this->customer,
        ])->layout('layouts.app');
    }

    private function fillFromCustomer(): void
    {
        $this->formatNric = $this->customer->wakalah_format_nric ?? true;
        $this->clientNric = $this->displayNric((string) ($this->customer->primaryIdentification?->number
            ?: $this->customer->primary_identification_number
            ?: ''));
        $this->designation = (string) ($this->customer->wakalah_designation ?? '');
        $this->witnessName = (string) ($this->customer->wakalah_witness_name ?? '');
        $this->witnessNric = (string) ($this->customer->wakalah_witness_nric ?? '');
        $this->witnessDate = $this->customer->wakalah_witness_date?->format('Y-m-d') ?? '';
    }

    private function displayNric(string $number): string
    {
        $type = $this->customer->primary_identification_type ?? $this->customer->primaryIdentification?->type;

        return $this->storeNric($number, $type, $this->formatNric) ?? '';
    }

    private function storeNric(string $number, ?IdentificationType $type, bool $format): ?string
    {
        if ($number === '') {
            return null;
        }

        if (! $format && $type === IdentificationType::Nric) {
            return CustomerIdentification::normalizeNumber($number);
        }

        return CustomerIdentification::formatNumber($number, $type);
    }
}
