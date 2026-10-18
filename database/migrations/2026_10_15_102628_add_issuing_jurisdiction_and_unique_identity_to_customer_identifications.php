<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $identifications = DB::table('customer_identifications')->orderBy('id')->get();
        $customers = DB::table('customers')->whereNotNull('primary_identification_number')->get();
        $keys = [];

        foreach ($identifications as $identification) {
            $number = $identification->number ?: $identification->normalized_number;
            $normalized = filled($number) ? strtoupper(preg_replace('/[\s\-]/u', '', trim($number)) ?? '') : null;

            if ($normalized === '') {
                $normalized = null;
            }

            if ($normalized !== null) {
                $key = implode('|', [$identification->type, $identification->type === 'nric' ? 'MY' : 'UNSPECIFIED', $normalized]);

                if (isset($keys[$key])) {
                    throw new RuntimeException("Conflicting customer identifications {$keys[$key]} and {$identification->id}; resolve them before migrating.");
                }

                $keys[$key] = $identification->id;
            }
        }

        foreach ($customers as $customer) {
            if ($identifications->contains(fn ($identification): bool => $identification->customer_id === $customer->id && (bool) $identification->is_primary)) {
                continue;
            }

            $type = $customer->primary_identification_type ?: 'other';
            $normalized = strtoupper(preg_replace('/[\s\-]/u', '', trim($customer->primary_identification_number)) ?? '');
            $key = implode('|', [$type, $type === 'nric' ? 'MY' : 'UNSPECIFIED', $normalized]);

            if (isset($keys[$key])) {
                throw new RuntimeException("Customer {$customer->id} conflicts with identification {$keys[$key]}; resolve them before migrating.");
            }

            $keys[$key] = "customer {$customer->id}";
        }

        Schema::table('customer_identifications', function (Blueprint $table) {
            $table->string('issuing_jurisdiction', 20)->default('UNSPECIFIED')->after('type');
        });

        foreach ($identifications as $identification) {
            $number = $identification->number ?: $identification->normalized_number;
            DB::table('customer_identifications')->where('id', $identification->id)->update([
                'issuing_jurisdiction' => $identification->type === 'nric' ? 'MY' : 'UNSPECIFIED',
                'normalized_number' => filled($number) ? strtoupper(preg_replace('/[\s\-]/u', '', trim($number)) ?? '') : null,
            ]);
        }

        foreach ($customers as $customer) {
            if ($identifications->contains(fn ($identification): bool => $identification->customer_id === $customer->id && (bool) $identification->is_primary)) {
                continue;
            }

            DB::table('customer_identifications')->insert([
                'customer_id' => $customer->id,
                'type' => $customer->primary_identification_type ?: 'other',
                'issuing_jurisdiction' => $customer->primary_identification_type === 'nric' ? 'MY' : 'UNSPECIFIED',
                'number' => $customer->primary_identification_number,
                'normalized_number' => strtoupper(preg_replace('/[\s\-]/u', '', trim($customer->primary_identification_number)) ?? ''),
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('customer_identifications', function (Blueprint $table) {
            $table->unique(['type', 'issuing_jurisdiction', 'normalized_number'], 'customer_identifications_identity_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_identifications', function (Blueprint $table) {
            $table->dropUnique('customer_identifications_identity_unique');
            $table->dropColumn('issuing_jurisdiction');
        });
    }
};
