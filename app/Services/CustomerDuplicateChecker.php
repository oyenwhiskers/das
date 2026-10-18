<?php

namespace App\Services;

use App\Enums\ContactType;
use App\Enums\IdentificationType;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerIdentification;
use Illuminate\Support\Collection;

class CustomerDuplicateChecker
{
    /**
     * @return array{strong: Collection<int, Customer>, weak: Collection<int, Customer>}
     */
    public function check(
        ?IdentificationType $identificationType,
        ?string $identificationNumber,
        ?string $email,
        ?string $mobile,
        ?string $fullLegalName,
        ?int $ignoreCustomerId = null,
        ?string $issuingJurisdiction = null,
    ): array {
        $strong = collect();
        $weak = collect();

        if ($identificationType && filled($identificationNumber)) {
            $normalized = CustomerIdentification::normalizeNumber($identificationNumber);

            $jurisdiction = $identificationType === IdentificationType::Nric ? 'MY' : strtoupper(trim((string) $issuingJurisdiction));
            $matches = Customer::query()
                ->whereHas('identifications', function ($query) use ($identificationType, $normalized, $jurisdiction): void {
                    $query->where('type', $identificationType)
                        ->where('issuing_jurisdiction', $jurisdiction)
                        ->where('normalized_number', $normalized);
                })
                ->when($ignoreCustomerId, fn ($query) => $query->where('id', '!=', $ignoreCustomerId))
                ->get();

            $strong = $strong->merge($matches);

            $relatedIdentifications = Customer::query()
                ->whereHas('identifications', function ($query) use ($identificationType, $normalized, $jurisdiction): void {
                    $query->where('type', $identificationType)
                        ->where('normalized_number', $normalized)
                        ->where('issuing_jurisdiction', '!=', $jurisdiction);
                })
                ->when($ignoreCustomerId, fn ($query) => $query->where('id', '!=', $ignoreCustomerId))
                ->get();

            $weak = $weak->merge($relatedIdentifications);
        }

        if (filled($email)) {
            $normalizedEmail = CustomerContact::normalizeValue(ContactType::Email, $email);

            $matches = Customer::query()
                ->where(function ($query) use ($normalizedEmail, $email): void {
                    $query->where('primary_email', $email)
                        ->orWhere('primary_email', $normalizedEmail)
                        ->orWhereHas('contacts', function ($contactQuery) use ($normalizedEmail): void {
                            $contactQuery->where('type', ContactType::Email)
                                ->where('normalized_value', $normalizedEmail);
                        });
                })
                ->when($ignoreCustomerId, fn ($query) => $query->where('id', '!=', $ignoreCustomerId))
                ->get();

            $weak = $weak->merge($matches);
        }

        if (filled($mobile)) {
            $normalizedMobile = CustomerContact::normalizeValue(ContactType::Mobile, $mobile);

            $matches = Customer::query()
                ->where(function ($query) use ($normalizedMobile, $mobile): void {
                    $query->where('primary_mobile', $mobile)
                        ->orWhereHas('contacts', function ($contactQuery) use ($normalizedMobile): void {
                            $contactQuery->where('type', ContactType::Mobile)
                                ->where('normalized_value', $normalizedMobile);
                        });
                })
                ->when($ignoreCustomerId, fn ($query) => $query->where('id', '!=', $ignoreCustomerId))
                ->get();

            $weak = $weak->merge($matches);
        }

        if (filled($fullLegalName)) {
            $normalizedName = $this->normalizeName($fullLegalName);

            $matches = Customer::query()
                ->when($ignoreCustomerId, fn ($query) => $query->where('id', '!=', $ignoreCustomerId))
                ->get()
                ->filter(fn (Customer $customer): bool => $this->normalizeName($customer->full_legal_name) === $normalizedName);

            $weak = $weak->merge($matches);
        }

        $strongIds = $strong->pluck('id')->all();

        return [
            'strong' => $strong->unique('id')->values(),
            'weak' => $weak->unique('id')->reject(fn (Customer $customer): bool => in_array($customer->id, $strongIds, true))->values(),
        ];
    }

    protected function normalizeName(string $name): string
    {
        $name = strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? ''));

        return $name;
    }
}
