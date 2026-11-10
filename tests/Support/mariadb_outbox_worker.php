<?php

use App\Services\Outbox\OutboxProcessor;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\TestDatabaseSafety;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = [
    'driver' => 'mysql',
    'url' => null,
    'host' => getenv('OUTBOX_MARIADB_HOST') ?: '127.0.0.1',
    'port' => getenv('OUTBOX_MARIADB_PORT') ?: '3307',
    'database' => getenv('OUTBOX_MARIADB_DATABASE') ?: '',
    'username' => getenv('OUTBOX_MARIADB_USERNAME') ?: '',
    'password' => getenv('OUTBOX_MARIADB_PASSWORD') ?: '',
    'unix_socket' => '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'prefix_indexes' => true,
    'strict' => true,
    'engine' => null,
    'options' => [],
];
TestDatabaseSafety::assertConnection($connection, getenv('OUTBOX_MARIADB_TEST') ?: null);
config(['database.connections.outbox_mariadb_test' => $connection, 'database.default' => 'outbox_mariadb_test', 'mail.default' => 'log']);
app('db')->purge('outbox_mariadb_test');

$messageId = (int) ($argv[1] ?? 0);
$gate = (string) ($argv[2] ?? '');
$deadline = microtime(true) + 20;
while (! is_file($gate)) {
    if (microtime(true) > $deadline) {
        exit(2);
    }
    usleep(10000);
}

echo app(OutboxProcessor::class)->process($messageId) ? 'claimed' : 'skipped';
