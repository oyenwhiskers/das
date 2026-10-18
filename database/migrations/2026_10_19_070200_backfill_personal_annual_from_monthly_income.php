<?php

use App\Services\CustomerProfileSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('customer_financial_declarations')
            ->whereNotNull('current_customer_key')
            ->whereNotNull('monthly_income_rm')
            ->where(function ($query): void {
                $query->whereNull('personal_annual_income_bracket')
                    ->orWhere('personal_annual_income_bracket', '');
            })
            ->get(['id', 'monthly_income_rm']);

        foreach ($rows as $row) {
            $bracket = CustomerProfileSchema::personalAnnualBracketFromMonthly($row->monthly_income_rm);
            if ($bracket === null) {
                continue;
            }
            DB::table('customer_financial_declarations')->where('id', $row->id)->update([
                'personal_annual_income_bracket' => $bracket,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // shortcut: leave suggested annual brackets in place; re-declare manually if a blank value is required
    }
};
