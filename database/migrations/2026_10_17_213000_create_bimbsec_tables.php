<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bimbsec_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->json('choices');
            $table->boolean('overflow_attachment_approved')->default(false);
            $table->string('overflow_attachment_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('bimbsec_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bimbsec_application_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->char('source_hash', 64);
            $table->char('template_sha256', 64);
            $table->char('mapping_sha256', 64);
            $table->string('storage_path');
            $table->unsignedSmallInteger('page_count');
            $table->json('warnings');
            $table->boolean('print_blocked')->default(false);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->timestamps();
        });

        Schema::create('bimbsec_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->json('application_ids');
            $table->json('summary')->nullable();
            $table->string('storage_path')->nullable();
            $table->boolean('complete')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bimbsec_batches');
        Schema::dropIfExists('bimbsec_documents');
        Schema::dropIfExists('bimbsec_applications');
    }
};
