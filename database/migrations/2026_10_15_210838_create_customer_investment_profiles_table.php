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
        Schema::create('customer_investment_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->json('objectives');
            $table->string('risk_tolerance', 20)->nullable();
            $table->string('trading_knowledge', 20)->nullable();
            $table->json('products');
            $table->string('other_product_details')->nullable();
            $table->string('information_source', 30)->default('customer_declared');
            $table->timestamp('declared_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'declared_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_investment_profiles');
    }
};
