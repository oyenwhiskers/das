<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_scans', function (Blueprint $table): void {
            $table->string('profile_status', 20)->default('not_started');
            $table->text('profile_error')->nullable();
            $table->json('profile_unresolved')->nullable();
        });

        Schema::create('extracted_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_scan_id')->constrained()->cascadeOnDelete();
            $table->string('record_ref', 30);
            $table->json('source_pages');
            $table->char('field_schema_hash', 64);
            $table->string('field_schema_version', 10);
            $table->string('contract_version', 10);
            $table->json('original_projection');
            $table->json('reviewed_projection');
            $table->unsignedInteger('revision')->default(0);
            $table->string('review_status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['document_scan_id', 'record_ref']);
        });

        Schema::create('extracted_profile_review_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('extracted_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users');
            $table->unsignedInteger('revision');
            $table->json('previous_value')->nullable();
            $table->json('new_value')->nullable();
            $table->text('reason');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extracted_profile_review_events');
        Schema::dropIfExists('extracted_profiles');
        Schema::table('document_scans', fn (Blueprint $table) => $table->dropColumn(['profile_status', 'profile_error', 'profile_unresolved']));
    }
};
