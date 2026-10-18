<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customer_tax_identifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('malaysian_tin')->nullable();
            $table->timestamps();
        });
        Schema::create('customer_tax_residencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('jurisdiction');
            $table->string('jurisdiction_key');
            $table->string('current_jurisdiction_key')->nullable();
            $table->text('foreign_tin')->nullable();
            $table->char('missing_tin_reason', 1)->nullable();
            $table->text('missing_tin_explanation')->nullable();
            $table->date('effective_date');
            $table->date('ended_on')->nullable();
            $table->timestamps();
            $table->unique(['customer_id', 'current_jurisdiction_key'], 'tax_residency_current_unique');
            $table->index(['customer_id', 'jurisdiction_key', 'effective_date'], 'tax_residency_history_index');
        });
        Schema::create('customer_fatca_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('current_customer_key')->nullable()->unique();
            $table->boolean('us_person_for_tax')->nullable();
            $table->boolean('us_green_card_holder')->nullable();
            $table->boolean('us_resident')->nullable();
            $table->date('declared_on');
            $table->string('source', 30);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'declared_on'], 'fatca_declaration_history_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_fatca_declarations');
        Schema::dropIfExists('customer_tax_residencies');
        Schema::dropIfExists('customer_tax_identifications');
    }
};
