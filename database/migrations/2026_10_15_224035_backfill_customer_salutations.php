<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['Male' => ['Mr.', 'Ms.'], 'Female' => ['Ms.', 'Mr.']] as $gender => [$salutation, $oppositeSalutation]) {
            DB::table('customers')
                ->where('gender', $gender)
                ->where(function (Builder $query) use ($oppositeSalutation): void {
                    $query->whereNull('salutation')
                        ->orWhere('salutation', '')
                        ->orWhere('salutation', $oppositeSalutation);
                })
                ->update(['salutation' => $salutation]);
        }
    }

    /**
     * Previous salutations cannot be distinguished from later edits on rollback.
     */
    public function down(): void {}
};
