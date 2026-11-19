<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_bssb_related_parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('name');
            $table->string('relationship')->nullable();
            $table->string('identification_number')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['customer_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_bssb_related_parties');
    }
};
