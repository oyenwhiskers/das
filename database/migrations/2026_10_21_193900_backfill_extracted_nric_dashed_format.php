<?php

use App\Services\ExtractedNricBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(ExtractedNricBackfill::class)->run();
    }

    public function down(): void {}
};
