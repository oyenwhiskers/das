<?php

use App\Services\NricParser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $parser = app(NricParser::class);

        DB::table('customers')
            ->select(['id', 'primary_identification_number', 'date_of_birth', 'gender', 'nationality'])
            ->where('primary_identification_type', 'nric')
            ->whereNotNull('primary_identification_number')
            ->orderBy('id')
            ->chunkById(100, static function (Collection $customers) use ($parser): void {
                foreach ($customers as $customer) {
                    $details = $parser->parse($customer->primary_identification_number);

                    if ($details === null) {
                        continue;
                    }

                    $missingDetails = [];

                    foreach ($details as $field => $value) {
                        if (blank($customer->{$field})) {
                            $missingDetails[$field] = $value;
                        }
                    }

                    if ($missingDetails !== []) {
                        DB::table('customers')->where('id', $customer->id)->update($missingDetails);
                    }
                }
            });
    }

    /**
     * Previously missing values cannot be distinguished from later edits on rollback.
     */
    public function down(): void {}
};
