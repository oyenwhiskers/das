<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('race')->nullable()->default('Malay')->change();
        });

        DB::table('customers')->update(['race' => 'Malay']);
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('race')->nullable()->default(null)->change();
        });
    }
};
