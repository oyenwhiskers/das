<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->string('occupancy_other')->nullable();
        });
        Schema::table('customer_contacts', function (Blueprint $table) {
            $table->string('platform', 30)->nullable();
            $table->unique(['customer_id', 'type', 'platform'], 'customer_contact_platform_unique');
        });
        Schema::table('related_people', function (Blueprint $table) {
            $table->string('employer_address_line_1')->nullable();
            $table->string('employer_address_line_2')->nullable();
            $table->string('employer_city')->nullable();
            $table->string('employer_state')->nullable();
            $table->string('employer_postcode', 20)->nullable();
            $table->string('employer_country')->nullable();
        });
        Schema::table('customer_fatca_declarations', function (Blueprint $table) {
            $table->string('selected_classification', 20)->nullable();
        });
        Schema::table('customer_pep_associations', function (Blueprint $table) {
            $table->string('occupation')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_pep_associations', function (Blueprint $table) {
            $table->dropColumn('occupation');
        });
        Schema::table('customer_fatca_declarations', function (Blueprint $table) {
            $table->dropColumn('selected_classification');
        });
        Schema::table('related_people', function (Blueprint $table) {
            $table->dropColumn(['employer_address_line_1', 'employer_address_line_2', 'employer_city', 'employer_state', 'employer_postcode', 'employer_country']);
        });
        Schema::table('customer_contacts', function (Blueprint $table) {
            $table->dropUnique('customer_contact_platform_unique');
            $table->dropColumn('platform');
        });
        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->dropColumn('occupancy_other');
        });
    }
};
