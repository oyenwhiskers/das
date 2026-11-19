<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bimbsec_applications', 'form_kind')) {
            Schema::table('bimbsec_applications', function (Blueprint $table) {
                $table->string('form_kind', 20)->default('individual')->after('customer_id');
            });
        }

        DB::table('bimbsec_applications')->where(function ($query): void {
            $query->whereNull('form_kind')->orWhere('form_kind', '');
        })->update(['form_kind' => 'individual']);

        $indexNames = collect(Schema::getIndexes('bimbsec_applications'))->pluck('name');
        $hasCustomerUnique = $indexNames->contains('bimbsec_applications_customer_id_unique');
        $hasCompositeUnique = $indexNames->contains('bimbsec_applications_customer_id_form_kind_unique');

        if ($hasCustomerUnique) {
            Schema::table('bimbsec_applications', function (Blueprint $table) {
                $table->dropForeign(['customer_id']);
            });
            Schema::table('bimbsec_applications', function (Blueprint $table) {
                $table->dropUnique(['customer_id']);
            });
        }

        if (! $hasCompositeUnique) {
            Schema::table('bimbsec_applications', function (Blueprint $table) {
                $table->unique(['customer_id', 'form_kind']);
            });
        }

        $foreignNames = collect(Schema::getForeignKeys('bimbsec_applications'))
            ->filter(fn (array $foreign): bool => in_array('customer_id', $foreign['columns'], true))
            ->pluck('name');

        if ($foreignNames->isEmpty()) {
            Schema::table('bimbsec_applications', function (Blueprint $table) {
                $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('bimbsec_applications', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropUnique(['customer_id', 'form_kind']);
        });

        DB::table('bimbsec_applications')->where('form_kind', 'corporate')->delete();

        Schema::table('bimbsec_applications', function (Blueprint $table) {
            $table->unique('customer_id');
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->dropColumn('form_kind');
        });
    }
};
