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
        Schema::create('customer_compliance_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('answer', 20);
            $table->text('remarks')->nullable();
            $table->date('declared_on');
            $table->string('source', 30);
            $table->string('confirmation_status', 30);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('current_key')->nullable()->unique();
            $table->timestamps();
            $table->index(['customer_id', 'kind', 'declared_on'], 'compliance_declaration_history');
        });

        Schema::create('customer_pep_associations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_relationship_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('related_person_id')->nullable()->constrained('related_people')->restrictOnDelete();
            $table->string('relationship');
            $table->string('position')->nullable();
            $table->string('organization')->nullable();
            $table->string('country')->nullable();
            $table->string('position_status', 30);
            $table->text('remarks')->nullable();
            $table->string('active_identity_key')->nullable()->unique();
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'ended_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_pep_associations');
        Schema::dropIfExists('customer_compliance_declarations');
    }
};
