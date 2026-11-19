<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('related_people', function (Blueprint $table) {
            $table->string('office_extension', 20)->nullable()->after('home_telephone');
        });

        Schema::table('customer_corporate_details', function (Blueprint $table) {
            $table->string('sst', 100)->nullable()->after('aum_bracket');
        });
    }

    public function down(): void
    {
        Schema::table('related_people', function (Blueprint $table) {
            $table->dropColumn('office_extension');
        });

        Schema::table('customer_corporate_details', function (Blueprint $table) {
            $table->dropColumn('sst');
        });
    }
};
