<?php

namespace Database\Seeders;

use App\Enums\IdentificationType;
use App\Enums\UserRole;
use App\Models\CustomerIdentification;
use App\Models\User;
use App\Services\CustomerCreator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

class CustomerListSeeder extends Seeder
{
    public function run(CustomerCreator $customerCreator): void
    {
        $contents = file_get_contents(base_path('ska_capital_names_ic.json'));

        if ($contents === false) {
            throw new RuntimeException('Customer list could not be read.');
        }

        try {
            $customers = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Customer list is not valid JSON.', previous: $exception);
        }

        if (! is_array($customers) || ! array_is_list($customers)) {
            throw new RuntimeException('Customer list must be a JSON array.');
        }

        $identificationNumbers = [];

        foreach ($customers as $customer) {
            if (! is_array($customer)
                || ! isset($customer['name'], $customer['ic_number'])
                || ! is_string($customer['name'])
                || ! is_string($customer['ic_number'])
                || trim($customer['name']) === ''
                || ! preg_match('/^\d{6}-\d{2}-\d{4}$/', $customer['ic_number'])) {
                throw new RuntimeException('Customer list contains an invalid entry.');
            }

            $normalizedNumber = CustomerIdentification::normalizeNumber($customer['ic_number']);

            if (isset($identificationNumbers[$normalizedNumber])) {
                throw new RuntimeException('Customer list contains a repeated IC number.');
            }

            $identificationNumbers[$normalizedNumber] = true;
        }

        $user = User::query()->where('role', UserRole::Admin->value)->firstOrFail();

        DB::transaction(function () use ($customers, $customerCreator, $user): void {
            foreach ($customers as $customer) {
                $normalizedNumber = CustomerIdentification::normalizeNumber($customer['ic_number']);

                $alreadyExists = CustomerIdentification::query()
                    ->where('type', IdentificationType::Nric->value)
                    ->where('issuing_jurisdiction', 'MY')
                    ->where('normalized_number', $normalizedNumber)
                    ->exists();

                if ($alreadyExists) {
                    continue;
                }

                $customerCreator->create([
                    'full_legal_name' => $customer['name'],
                    'identification_type' => IdentificationType::Nric->value,
                    'identification_number' => $customer['ic_number'],
                ], $user);
            }
        });
    }
}
