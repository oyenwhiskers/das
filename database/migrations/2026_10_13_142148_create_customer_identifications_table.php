<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_identifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('number')->nullable();
            $table->string('normalized_number')->nullable()->index();
            $table->string('old_nric')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['type', 'normalized_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_identifications');
    }
};
