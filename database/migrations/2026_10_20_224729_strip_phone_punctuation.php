<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $strip = static fn (?string $value): ?string => blank($value)
            ? null
            : (preg_replace('/[^\d+]/', '', $value) ?: null);

        DB::table('customers')
            ->select(['id', 'primary_mobile'])
            ->whereNotNull('primary_mobile')
            ->where('primary_mobile', '!=', '')
            ->orderBy('id')
            ->chunkById(100, static function (Collection $rows) use ($strip): void {
                foreach ($rows as $row) {
                    $normalized = $strip($row->primary_mobile);
                    if ($normalized === null || $normalized === $row->primary_mobile) {
                        continue;
                    }
                    DB::table('customers')->where('id', $row->id)->update([
                        'primary_mobile' => $normalized,
                        'updated_at' => now(),
                    ]);
                }
            });

        DB::table('customer_contacts')
            ->select(['id', 'value', 'normalized_value'])
            ->whereIn('type', ['mobile', 'home'])
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->orderBy('id')
            ->chunkById(100, static function (Collection $rows) use ($strip): void {
                foreach ($rows as $row) {
                    $normalized = $strip($row->value);
                    if ($normalized === null) {
                        continue;
                    }
                    if ($normalized === $row->value && $normalized === $row->normalized_value) {
                        continue;
                    }
                    DB::table('customer_contacts')->where('id', $row->id)->update([
                        'value' => $normalized,
                        'normalized_value' => $normalized,
                        'updated_at' => now(),
                    ]);
                }
            });

        DB::table('related_people')
            ->select(['id', 'mobile', 'home_telephone'])
            ->orderBy('id')
            ->chunkById(100, static function (Collection $rows) use ($strip): void {
                foreach ($rows as $row) {
                    $mobile = filled($row->mobile) ? $strip($row->mobile) : $row->mobile;
                    $home = filled($row->home_telephone) ? $strip($row->home_telephone) : $row->home_telephone;
                    if ($mobile === $row->mobile && $home === $row->home_telephone) {
                        continue;
                    }
                    DB::table('related_people')->where('id', $row->id)->update([
                        'mobile' => $mobile,
                        'home_telephone' => $home,
                        'updated_at' => now(),
                    ]);
                }
            });

        DB::table('customer_employments')
            ->select(['id', 'general_telephone', 'direct_telephone', 'fax'])
            ->orderBy('id')
            ->chunkById(100, static function (Collection $rows) use ($strip): void {
                foreach ($rows as $row) {
                    $general = filled($row->general_telephone) ? $strip($row->general_telephone) : $row->general_telephone;
                    $direct = filled($row->direct_telephone) ? $strip($row->direct_telephone) : $row->direct_telephone;
                    $fax = filled($row->fax) ? $strip($row->fax) : $row->fax;
                    if ($general === $row->general_telephone
                        && $direct === $row->direct_telephone
                        && $fax === $row->fax) {
                        continue;
                    }
                    DB::table('customer_employments')->where('id', $row->id)->update([
                        'general_telephone' => $general,
                        'direct_telephone' => $direct,
                        'fax' => $fax,
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible: original dashes/spaces are not retained.
    }
};
