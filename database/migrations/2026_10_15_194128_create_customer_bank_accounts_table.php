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
        Schema::create('customer_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('bank_name');
            $table->string('bank_country');
            $table->string('branch_address')->nullable();
            $table->string('account_type', 20);
            $table->text('account_number');
            $table->string('account_holder_name');
            $table->string('ownership', 20)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('primary_active_key')->nullable()->unique();
            $table->char('identity_hash', 64);
            $table->timestamps();
            $table->unique(['customer_id', 'identity_hash']);
            $table->index(['customer_id', 'is_active', 'is_primary']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_bank_accounts');
    }
};
