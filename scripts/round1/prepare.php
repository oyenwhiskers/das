<?php

require __DIR__.'/bootstrap.php';

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

Artisan::call('migrate', ['--force' => true]);
echo Artisan::output();

if ((string) config('database.default') !== 'sqlite' || ! str_ends_with((string) config('database.connections.sqlite.database'), 'das_round1.sqlite')) {
    fwrite(STDERR, "Refusing to prepare: migrations did not stay on the isolated database.\n");
    exit(1);
}

User::query()->firstOrCreate(
    ['email' => 'round1-staff@example.net'],
    [
        'name' => 'Round 1 Staff',
        'password' => Hash::make(bin2hex(random_bytes(16))),
        'role' => UserRole::Staff,
        'is_active' => true,
    ],
);

$customers = Customer::query()->count();
if ($customers !== 0) {
    fwrite(STDERR, "Refusing to prepare: customer count is {$customers}.\n");
    exit(1);
}

require __DIR__.'/HttpCeiling.php';
$ledger = dirname(__DIR__, 2).'/storage/round1/http-ledger.json';
if (! is_file($ledger)) {
    (new Round1HttpCeiling($ledger, 6))->write(0);
}

echo "isolated database ready, customers=0\n";
