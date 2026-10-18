<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_scan_id')->nullable()->constrained('document_scans')->nullOnDelete();
            $table->string('model');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('thought_tokens')->nullable();
            $table->unsignedBigInteger('cached_tokens')->nullable();
            $table->decimal('cost_usd', 14, 6)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usages');
    }
};
