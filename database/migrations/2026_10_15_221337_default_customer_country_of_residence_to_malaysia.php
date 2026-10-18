<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('country_of_residence')->nullable()->default('Malaysia')->change();
        });

        DB::table('customers')
            ->whereNull('country_of_residence')
            ->orWhere('country_of_residence', '')
            ->update(['country_of_residence' => 'Malaysia']);
    }

    /**
     * Previously missing values cannot be distinguished from later edits on rollback.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('country_of_residence')->nullable()->default(null)->change();
        });
    }
};
