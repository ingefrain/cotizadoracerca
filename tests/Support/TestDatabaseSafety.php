<?php

namespace Tests\Support;

use Illuminate\Database\Connectors\ConnectionFactory;
use RuntimeException;

final class TestDatabaseSafety
{
    public static function assertConnection(array $config, ?string $activationFlag = null, array $protectedDatabases = []): void
    {
        if (! empty($config['url']) || ! empty($config['read']) || ! empty($config['write'])
            || ! empty($config['unix_socket']) || ! empty($config['prefix'])
            || ! empty($config['options']) || ! empty($config['dsn'])) {
            throw new RuntimeException('Test database URLs and read/write overrides are forbidden.');
        }

        if (($config['driver'] ?? '') === 'sqlite' && ($config['database'] ?? '') === ':memory:') {
            return;
        }

        $database = (string) ($config['database'] ?? '');
        if ($activationFlag === '1'
            && in_array($config['driver'] ?? '', ['mysql', 'mariadb'], true)
            && $database === 'cotizadoracerca_test'
            && ! in_array(strtolower($database), array_map('strtolower', $protectedDatabases), true)
            && ($config['host'] ?? '') === '127.0.0.1'
            && (string) ($config['port'] ?? '') === '3307'
            && ($config['username'] ?? '') === 'cotizadoracerca_test_runner') {
            return;
        }

        throw new RuntimeException('Unsafe test database connection rejected before opening PDO.');
    }

    public static function install($app): void
    {
        if (! $app->environment('testing')) {
            throw new RuntimeException('Tests require APP_ENV=testing.');
        }

        $manager = $app->make('db');
        foreach (['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv'] as $driver) {
            $manager->extend($driver, function ($config, $name) use ($app) {
                self::assertConnection($config, getenv('OUTBOX_MARIADB_TEST') ?: null);

                return (new ConnectionFactory($app))->make($config, $name);
            });
        }
        self::assertConnection(config('database.connections.'.config('database.default')), getenv('OUTBOX_MARIADB_TEST') ?: null);
    }
}
