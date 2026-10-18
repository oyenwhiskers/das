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
        Schema::create('customer_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_person_id')->nullable()->constrained('related_people')->restrictOnDelete();
            $table->foreignId('related_customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->string('relationship');
            $table->string('other_relationship')->nullable();
            $table->boolean('is_next_of_kin')->default(false);
            $table->boolean('is_primary_next_of_kin')->default(false);
            $table->string('active_identity_key')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'deleted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_relationships');
    }
};
