<?php

use App\Models\BimbsecApplication;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (BimbsecApplication::query()->orderBy('id')->cursor() as $application) {
            $choices = $application->choices ?? [];
            if (($choices['verification_mode'] ?? null) !== 'non_face') {
                continue;
            }
            $choices['verification_mode'] = null;
            $application->choices = $choices;
            $application->save();
            // Force PDF rebuild on next view; old files were drawn with the default tick.
            DB::table('bimbsec_documents')
                ->where('bimbsec_application_id', $application->id)
                ->update(['source_hash' => '', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        //
    }
};
