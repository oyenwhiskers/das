<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_scans', function (Blueprint $table) {
            $table->string('extraction_error')->nullable()->after('extraction_status');
        });
    }

    public function down(): void
    {
        Schema::table('document_scans', function (Blueprint $table) {
            $table->dropColumn('extraction_error');
        });
    }
};
