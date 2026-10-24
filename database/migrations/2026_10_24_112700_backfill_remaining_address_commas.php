<?php

use App\Services\ExtractedAddressCommaBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(ExtractedAddressCommaBackfill::class)->run();
    }

    public function down(): void {}
};
