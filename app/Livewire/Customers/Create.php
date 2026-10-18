<?php

namespace App\Livewire\Customers;

use App\Enums\IdentificationType;
use App\Models\Customer;
use App\Models\CustomerIdentification;
use App\Services\CustomerCreator;
use App\Services\CustomerDuplicateChecker;
use App\Services\NricParser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Add Customer — DAS')]
class Create extends Component
{
    use AuthorizesRequests;

    public string $full_legal_name = '';

    public string $identification_type = '';

    public string $identification_number = '';

    public string $issuing_jurisdiction = '';

    public string $date_of_birth = '';

    public string $gender = '';

    public string $mobile = '';

    public string $email = '';

    public string $nationality = '';

    public bool $confirmWeakDuplicates = false;

    /** @var list<array{id: int, reference: string, full_legal_name: string, match_type: string}> */
    public array $strongMatches = [];

    /** @var list<array{id: int, reference: string, full_legal_name: string, match_type: string}> */
    public array $weakMatches = [];

    public bool $saving = false;

    public function mount(): void
    {
        $this->authorize('create', Customer::class);
    }

    public function updatedIdentificationType(): void
    {
        $this->applyNricSuggestions();
    }

    public function updatedIdentificationNumber(): void
    {
        $this->applyNricSuggestions();
    }

    public function save(CustomerDuplicateChecker $duplicateChecker, CustomerCreator $creator): void
    {
        $this->authorize('create', Customer::class);
        $this->applyNricSuggestions();

        $validated = $this->validate($this->rules());

        $identificationType = filled($validated['identification_type'] ?? null)
            ? IdentificationType::from($validated['identification_type'])
            : null;

        $matches = $duplicateChecker->check(
            $identificationType,
            $validated['identification_number'] ?: null,
            $validated['email'] ?: null,
            $validated['mobile'] ?: null,
            $validated['full_legal_name'],
            issuingJurisdiction: $validated['issuing_jurisdiction'] ?: null,
        );

        $this->strongMatches = $matches['strong']->map(fn (Customer $customer): array => [
            'id' => $customer->id,
            'reference' => $customer->reference,
            'full_legal_name' => $customer->full_legal_name,
            'match_type' => 'Identification',
        ])->all();

        $this->weakMatches = $matches['weak']->map(fn (Customer $customer): array => [
            'id' => $customer->id,
            'reference' => $customer->reference,
            'full_legal_name' => $customer->full_legal_name,
            'match_type' => 'Possible match',
        ])->all();

        if ($matches['strong']->isNotEmpty()) {
            return;
        }

        if ($matches['weak']->isNotEmpty() && ! $this->confirmWeakDuplicates) {
            return;
        }

        $this->saving = true;

        try {
            $customer = $creator->create([
                'full_legal_name' => $validated['full_legal_name'],
                'identification_type' => $validated['identification_type'] ?: null,
                'identification_number' => $validated['identification_number'] ?: null,
                'issuing_jurisdiction' => $validated['issuing_jurisdiction'] ?: null,
                'date_of_birth' => $validated['date_of_birth'] ?: null,
                'gender' => $validated['gender'] ?: null,
                'mobile' => $validated['mobile'] ?: null,
                'email' => $validated['email'] ?: null,
                'nationality' => $validated['nationality'] ?: null,
            ], auth()->user());
        } catch (UniqueConstraintViolationException $exception) {
            $matches = $duplicateChecker->check($identificationType, $validated['identification_number'] ?: null, null, null, null, issuingJurisdiction: $validated['issuing_jurisdiction'] ?: null);

            if ($matches['strong']->isEmpty()) {
                throw $exception;
            }

            $this->strongMatches = $matches['strong']->map(fn (Customer $match): array => [
                'id' => $match->id,
                'reference' => $match->reference,
                'full_legal_name' => $match->full_legal_name,
                'match_type' => 'Identification',
            ])->all();

            return;
        }

        session()->flash('status', 'Customer created successfully.');

        $this->redirect(route('customers.show', $customer), navigate: true);
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
        }

        if (blank($this->nationality)) {
            $this->nationality = $parsed['nationality'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'full_legal_name' => ['required', 'string', 'max:255'],
            'issuing_jurisdiction' => ['required_if:identification_type,passport,other', 'nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'identification_number' => [
                'required_with:identification_type',
                'string',
                'max:100',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (blank($value) || blank($this->identification_type)) {
                        return;
                    }

                    if ($this->identification_type === IdentificationType::Nric->value) {
                        $normalized = CustomerIdentification::normalizeNumber((string) $value) ?? '';
                        if (! preg_match('/^\d{12}$/', $normalized)) {
                            $fail('NRIC must be 12 digits (with or without dashes).');
                        }
                    }
                },
            ],
            'identification_type' => ['required_with:identification_number', Rule::enum(IdentificationType::class)],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:30', 'regex:/^[+0-9\s\-()]{6,30}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'nationality' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function render(): View
    {
        return view('livewire.customers.create')->layout('layouts.app');
    }
}
