<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachment_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('original_filename');
            $table->string('storage_path')->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('status', 20)->default('pending')->index();
            $table->string('gemini_model')->nullable();
            $table->string('attachment_type', 32)->nullable()->index();
            $table->string('extracted_name')->nullable();
            $table->string('extracted_identification_number')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('attached_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processing_completed_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['customer_id', 'attachment_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachment_scans');
    }
};
