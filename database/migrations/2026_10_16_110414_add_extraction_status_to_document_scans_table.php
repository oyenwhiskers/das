<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_scans', function (Blueprint $table) {
            $table->string('extraction_status', 20)->default('not_started')->index();
        });
    }

    public function down(): void
    {
        Schema::table('document_scans', function (Blueprint $table) {
            $table->dropColumn('extraction_status');
        });
    }
};
