<?php

namespace App\Services;

use App\Enums\ContactType;
use App\Enums\CustomerActivityAction;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\IdentificationType;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerIdentification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CustomerCreator
{
    public function __construct(
        protected CustomerReferenceGenerator $referenceGenerator,
        protected CustomerActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array{
     *     full_legal_name: string,
     *     identification_type?: string|null,
     *     identification_number?: string|null,
     *     issuing_jurisdiction?: string|null,
     *     date_of_birth?: string|null,
     *     gender?: string|null,
     *     mobile?: string|null,
     *     email?: string|null,
     *     nationality?: string|null
     * }  $data
     */
    public function create(array $data, User $user): Customer
    {
        return DB::transaction(function () use ($data, $user): Customer {
            $identificationType = filled($data['identification_type'] ?? null)
                ? IdentificationType::from($data['identification_type'])
                : null;
            $identificationNumber = filled($data['identification_number'] ?? null)
                ? trim($data['identification_number'])
                : null;
            $issuingJurisdiction = $identificationType === IdentificationType::Nric
                ? 'MY'
                : (filled($data['issuing_jurisdiction'] ?? null) ? strtoupper(trim($data['issuing_jurisdiction'])) : null);

            if ($identificationType !== null && $identificationNumber === null) {
                throw new \InvalidArgumentException('Identification type and number must be provided together.');
            }

            if ($identificationType !== null && $identificationNumber !== null && $issuingJurisdiction === null) {
                throw new \InvalidArgumentException('Issuing jurisdiction is required for this identification type.');
            }
            $mobile = filled($data['mobile'] ?? null) ? trim($data['mobile']) : null;
            $email = filled($data['email'] ?? null) ? trim($data['email']) : null;

            $customer = Customer::query()->create([
                'reference' => $this->referenceGenerator->generate(),
                'type' => CustomerType::Individual,
                'status' => CustomerStatus::Active,
                'full_legal_name' => trim($data['full_legal_name']),
                'date_of_birth' => filled($data['date_of_birth'] ?? null) ? $data['date_of_birth'] : null,
                'gender' => filled($data['gender'] ?? null) ? trim($data['gender']) : null,
                'nationality' => filled($data['nationality'] ?? null) ? trim($data['nationality']) : null,
                'primary_identification_type' => $identificationType,
                'primary_identification_number' => $identificationNumber,
                'primary_mobile' => $mobile,
                'primary_email' => $email,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            if ($identificationType && $identificationNumber) {
                CustomerIdentification::query()->create([
                    'customer_id' => $customer->id,
                    'type' => $identificationType ?? IdentificationType::Other,
                    'number' => $identificationNumber,
                    'normalized_number' => CustomerIdentification::normalizeNumber($identificationNumber),
                    'issuing_jurisdiction' => $issuingJurisdiction,
                    'is_primary' => true,
                ]);
            }

            if ($mobile) {
                CustomerContact::query()->create([
                    'customer_id' => $customer->id,
                    'type' => ContactType::Mobile,
                    'value' => $mobile,
                    'normalized_value' => CustomerContact::normalizeValue(ContactType::Mobile, $mobile),
                    'is_primary' => true,
                ]);
            }

            if ($email) {
                CustomerContact::query()->create([
                    'customer_id' => $customer->id,
                    'type' => ContactType::Email,
                    'value' => $email,
                    'normalized_value' => CustomerContact::normalizeValue(ContactType::Email, $email),
                    'is_primary' => true,
                ]);
            }

            $this->activityLogger->log($customer, CustomerActivityAction::Created, $user);

            return $customer->fresh(['identifications', 'contacts']);
        });
    }
}
