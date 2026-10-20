<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['savings' => 'SAVINGS', 'current' => 'CURRENT', 'other' => 'OTHER'] as $from => $to) {
            DB::table('customer_bank_accounts')->where('account_type', $from)->update([
                'account_type' => $to,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (['SAVINGS' => 'savings', 'CURRENT' => 'current', 'OTHER' => 'other'] as $from => $to) {
            DB::table('customer_bank_accounts')->where('account_type', $from)->update([
                'account_type' => $to,
                'updated_at' => now(),
            ]);
        }
    }
};
