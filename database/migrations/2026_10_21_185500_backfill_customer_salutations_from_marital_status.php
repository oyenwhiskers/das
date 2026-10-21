<?php

use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Customer::query()->orderBy('id')->each(function (Customer $customer): void {
            $default = Customer::defaultSalutationForGender($customer->gender, $customer->marital_status);
            if ($default === null) {
                return;
            }

            $current = trim((string) $customer->salutation);
            $automatic = $current === ''
                || in_array(strtolower($current), array_map(strtolower(...), Customer::AUTOMATIC_SALUTATIONS), true);
            if (! $automatic || strcasecmp($current, $default) === 0) {
                return;
            }

            $customer->forceFill(['salutation' => $default])->saveQuietly();
        });
    }

    /**
     * Previous salutations cannot be distinguished from later edits on rollback.
     */
    public function down(): void {}
};
