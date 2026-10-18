<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_scans', fn (Blueprint $table) => $table->json('record_plan')->nullable());
        Schema::table('document_findings', fn (Blueprint $table) => $table->json('record_ownership')->nullable());
    }

    public function down(): void
    {
        Schema::table('document_findings', fn (Blueprint $table) => $table->dropColumn('record_ownership'));
        Schema::table('document_scans', fn (Blueprint $table) => $table->dropColumn('record_plan'));
    }
};
