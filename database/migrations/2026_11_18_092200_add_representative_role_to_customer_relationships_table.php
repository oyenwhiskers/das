<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_relationships', function (Blueprint $table) {
            $table->string('representative_role')->nullable()->after('other_relationship');
            $table->unsignedTinyInteger('signatory_order')->nullable()->after('representative_role');
        });
    }

    public function down(): void
    {
        Schema::table('customer_relationships', function (Blueprint $table) {
            $table->dropColumn(['representative_role', 'signatory_order']);
        });
    }
};
