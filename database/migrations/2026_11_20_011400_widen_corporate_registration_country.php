<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_corporate_details', function (Blueprint $table) {
            // BIMBSEC corporate form: Country of Registration character cells (32).
            $table->string('registration_country', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('customer_corporate_details', function (Blueprint $table) {
            $table->string('registration_country', 2)->nullable()->change();
        });
    }
};
