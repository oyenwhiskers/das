<?php

use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 2);
$database = $root.'/storage/round1/das_round1.sqlite';

if (! is_dir(dirname($database))) {
    mkdir(dirname($database), 0775, true);
}
if (! is_file($database)) {
    touch($database);
}

foreach ([
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $database,
    'DB_URL' => '',
] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connected = (string) config('database.default');
$configured = (string) config('database.connections.sqlite.database');
if ($connected !== 'sqlite' || $configured !== $database) {
    fwrite(STDERR, "Refusing to start: database is {$connected} {$configured}\n");
    exit(1);
}
