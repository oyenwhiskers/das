<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_profile_schema_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->char('hash', 64)->unique();
            $table->string('version', 20);
            $table->longText('canonical_json');
            $table->timestamps();
        });

        Schema::table('document_scans', function (Blueprint $table): void {
            $table->char('profile_schema_hash', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('document_scans', fn (Blueprint $table) => $table->dropColumn('profile_schema_hash'));
        Schema::dropIfExists('customer_profile_schema_snapshots');
    }
};
