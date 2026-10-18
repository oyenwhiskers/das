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
        Schema::create('related_people', function (Blueprint $table) {
            $table->id();
            $table->string('full_legal_name');
            $table->string('identification_type')->nullable();
            $table->text('identification_number')->nullable();
            $table->string('normalized_identification_number')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 50)->nullable();
            $table->string('nationality', 100)->nullable();
            $table->string('mobile', 30)->nullable();
            $table->string('home_telephone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('occupation')->nullable();
            $table->string('employer_name')->nullable();
            $table->text('employer_address')->nullable();
            $table->timestamps();

            $table->index(['identification_type', 'normalized_identification_number'], 'related_people_identity_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('related_people');
    }
};
