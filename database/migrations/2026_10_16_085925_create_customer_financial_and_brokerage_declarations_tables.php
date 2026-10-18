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
        Schema::create('customer_financial_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('current_customer_key')->nullable()->unique();
            $table->decimal('monthly_income_rm', 15, 2)->nullable();
            $table->string('personal_annual_income_bracket', 40)->nullable();
            $table->string('joint_annual_income_bracket', 40)->nullable();
            $table->string('net_asset_bracket', 40)->nullable();
            $table->string('portfolio_bracket', 40)->nullable();
            $table->string('portfolio_ownership', 20)->nullable();
            $table->json('wealth_sources');
            $table->string('other_wealth_source')->nullable();
            $table->date('declared_on');
            $table->string('source', 30);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'declared_on']);
        });

        Schema::create('customer_brokerage_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('current_customer_key')->nullable()->unique();
            $table->string('answer', 20);
            $table->date('declared_on');
            $table->string('source', 30);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'declared_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_brokerage_declarations');
        Schema::dropIfExists('customer_financial_declarations');
    }
};
