<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extracted_profile_merges', function (Blueprint $table): void {
            $table->id();
            $table->string('submission_key')->unique();
            $table->foreignId('extracted_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('reviewer_revision');
            $table->string('strategy', 20);
            $table->json('changes');
            $table->foreignId('merged_by')->constrained('users');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extracted_profile_merges');
    }
};
