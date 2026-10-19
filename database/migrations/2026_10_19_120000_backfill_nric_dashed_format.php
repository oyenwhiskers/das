<?php

use App\Enums\IdentificationType;
use App\Models\CustomerIdentification;
use App\Models\RelatedPerson;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('customer_identifications')
            ->select(['id', 'number', 'normalized_number'])
            ->where('type', IdentificationType::Nric->value)
            ->whereNotNull('number')
            ->orderBy('id')
            ->chunkById(100, static function (Collection $rows): void {
                foreach ($rows as $row) {
                    $formatted = CustomerIdentification::formatNumber($row->number, IdentificationType::Nric);
                    if ($formatted === null || $formatted === $row->number) {
                        continue;
                    }

                    DB::table('customer_identifications')->where('id', $row->id)->update([
                        'number' => $formatted,
                        'normalized_number' => CustomerIdentification::normalizeNumber($formatted),
                        'updated_at' => now(),
                    ]);
                }
            });

        DB::table('customers')
            ->select(['id', 'primary_identification_number'])
            ->where('primary_identification_type', IdentificationType::Nric->value)
            ->whereNotNull('primary_identification_number')
            ->orderBy('id')
            ->chunkById(100, static function (Collection $customers): void {
                foreach ($customers as $customer) {
                    $formatted = CustomerIdentification::formatNumber($customer->primary_identification_number, IdentificationType::Nric);
                    if ($formatted === null || $formatted === $customer->primary_identification_number) {
                        continue;
                    }

                    DB::table('customers')->where('id', $customer->id)->update([
                        'primary_identification_number' => $formatted,
                        'updated_at' => now(),
                    ]);
                }
            });

        // related_people.identification_number is encrypted — must go through Eloquent
        RelatedPerson::query()
            ->where('identification_type', IdentificationType::Nric->value)
            ->whereNotNull('identification_number')
            ->orderBy('id')
            ->chunkById(100, static function (Collection $people): void {
                foreach ($people as $person) {
                    $formatted = CustomerIdentification::formatNumber($person->identification_number, IdentificationType::Nric);
                    if ($formatted === null || $formatted === $person->identification_number) {
                        continue;
                    }

                    $person->update([
                        'identification_number' => $formatted,
                        'normalized_identification_number' => CustomerIdentification::normalizeNumber($formatted),
                    ]);
                }
            });
    }

    /**
     * Original display formats cannot be restored after rewriting dashes.
     */
    public function down(): void {}
};
