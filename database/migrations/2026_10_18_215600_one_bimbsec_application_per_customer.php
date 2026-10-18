<?php

use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $customerIds = DB::table('bimbsec_applications')
            ->select('customer_id')
            ->groupBy('customer_id')
            ->havingRaw('count(*) > 1')
            ->pluck('customer_id');

        foreach ($customerIds as $customerId) {
            $customer = Customer::query()->find($customerId);
            $keep = $customer?->currentBimbsecApplication();
            if ($customer === null || $keep === null) {
                continue;
            }
            $customer->bimbsecApplications()->whereKeyNot($keep->id)->get()->each->delete();
        }

        Schema::table('bimbsec_applications', function (Blueprint $table) {
            $table->unique('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('bimbsec_applications', function (Blueprint $table) {
            $table->dropUnique(['customer_id']);
        });
    }
};
