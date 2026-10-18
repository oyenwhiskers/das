<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $customers = DB::table('customers')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('customer_fatca_declarations')
                    ->whereColumn('customer_fatca_declarations.current_customer_key', 'customers.id');
            })
            ->get(['id', 'created_by', 'created_at']);

        foreach ($customers as $customer) {
            DB::table('customer_fatca_declarations')->insert([
                'customer_id' => $customer->id,
                'current_customer_key' => $customer->id,
                'us_person_for_tax' => false,
                'us_green_card_holder' => false,
                'us_resident' => false,
                'selected_classification' => 'non_us_person',
                'declared_on' => substr((string) $customer->created_at, 0, 10),
                'source' => 'system_default',
                'recorded_by' => $customer->created_by,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('customer_fatca_declarations')->where('source', 'system_default')->delete();
    }
};
