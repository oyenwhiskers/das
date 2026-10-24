<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('wakalah_designation')->nullable();
            $table->string('wakalah_witness_name')->nullable();
            $table->string('wakalah_witness_nric')->nullable();
            $table->date('wakalah_witness_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'wakalah_designation',
                'wakalah_witness_name',
                'wakalah_witness_nric',
                'wakalah_witness_date',
            ]);
        });
    }
};
