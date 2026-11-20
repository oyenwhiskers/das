<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_corporate_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('registered_at')->nullable();
            $table->string('registration_country', 32)->nullable();
            $table->string('entity_type')->nullable();
            $table->string('entity_sub_type')->nullable();
            $table->string('industry')->nullable();
            $table->string('business_activity')->nullable();
            $table->decimal('authorized_capital_rm', 18, 2)->nullable();
            $table->decimal('paid_up_capital_rm', 18, 2)->nullable();
            $table->string('aum_bracket')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_corporate_details');
    }
};
