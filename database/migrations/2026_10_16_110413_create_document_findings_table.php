<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_scan_id')->unique()->constrained('document_scans')->cascadeOnDelete();
            $table->string('schema_version', 10);
            $table->json('observations');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_findings');
    }
};
