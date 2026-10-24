<?php

use App\Services\ExtractedPhoneBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(ExtractedPhoneBackfill::class)->run();
    }

    public function down(): void {}
};
