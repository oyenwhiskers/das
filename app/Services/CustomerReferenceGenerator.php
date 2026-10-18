<?php

namespace App\Services;

use App\Models\CustomerReferenceCounter;
use Illuminate\Support\Facades\DB;

class CustomerReferenceGenerator
{
    public function generate(): string
    {
        return DB::transaction(function (): string {
            $counter = CustomerReferenceCounter::query()
                ->where('name', 'customer')
                ->lockForUpdate()
                ->first();

            if ($counter === null) {
                $counter = CustomerReferenceCounter::query()->create([
                    'name' => 'customer',
                    'last_value' => 0,
                ]);

                $counter = CustomerReferenceCounter::query()
                    ->where('name', 'customer')
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $counter->last_value++;
            $counter->save();

            return 'DAS-'.str_pad((string) $counter->last_value, 6, '0', STR_PAD_LEFT);
        });
    }
}
