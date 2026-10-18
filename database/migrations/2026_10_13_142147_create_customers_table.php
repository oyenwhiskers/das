<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('type')->default('individual');
            $table->string('status')->default('active')->index();
            $table->string('salutation')->nullable();
            $table->string('full_legal_name');
            $table->date('date_of_birth')->nullable();
            $table->string('gender')->nullable();
            $table->string('nationality')->nullable();
            $table->string('country_of_residence')->nullable();
            $table->string('race')->nullable();
            $table->string('marital_status')->nullable();
            $table->string('primary_identification_type')->nullable();
            $table->string('primary_identification_number')->nullable()->index();
            $table->string('primary_mobile')->nullable()->index();
            $table->string('primary_email')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('full_legal_name');
            $table->index('created_at');
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
