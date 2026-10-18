<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extracted_profiles', fn (Blueprint $table) => $table->string('prompt_version', 10)->default('1'));
    }

    public function down(): void
    {
        Schema::table('extracted_profiles', fn (Blueprint $table) => $table->dropColumn('prompt_version'));
    }
};
