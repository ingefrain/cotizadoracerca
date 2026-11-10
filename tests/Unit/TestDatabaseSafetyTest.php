<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseSafety;

class TestDatabaseSafetyTest extends TestCase
{
    public function test_in_memory_sqlite_and_the_dedicated_local_mariadb_database_are_allowed(): void
    {
        TestDatabaseSafety::assertConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        TestDatabaseSafety::assertConnection([
            'driver' => 'mysql',
            'database' => 'cotizadoracerca_test',
            'host' => '127.0.0.1',
            'port' => '3307',
            'username' => 'cotizadoracerca_test_runner',
        ], '1');

        $this->assertTrue(true);
    }

    public function test_non_test_databases_remote_hosts_urls_and_overrides_are_rejected(): void
    {
        $safe = [
            'driver' => 'mysql',
            'database' => 'cotizadoracerca_test',
            'host' => '127.0.0.1',
            'port' => '3307',
            'username' => 'cotizadoracerca_test_runner',
        ];

        foreach ([
            ['database' => 'proyectobase_dev'],
            ['database' => 'proyectobase_test', 'username' => 'proyectobase_test_runner'],
            ['database' => 'cotizadoracerca_dev'],
            ['database' => 'cotizadoracerca_prod'],
            ['database' => 'another_test', 'username' => 'another_test_runner'],
            ['database' => 'COTIZADORACERCA_TEST'],
            ['database' => 'clap_dev'],
            ['database' => 'acerca_prod'],
            ['host' => 'db.example.com'],
            ['host' => 'localhost'],
            ['host' => '::1'],
            ['port' => '3306'],
            ['username' => 'root'],
            ['url' => 'mysql://localhost/proyectobase_test'],
            ['read' => ['host' => 'localhost']],
            ['write' => ['host' => 'localhost']],
            ['unix_socket' => 'C:\\mysql.sock'],
            ['prefix' => 'production_'],
            ['options' => [1002 => 'USE proyectobase_dev']],
            ['dsn' => 'mysql:host=127.0.0.1;dbname=proyectobase_dev'],
        ] as $override) {
            try {
                TestDatabaseSafety::assertConnection(array_replace($safe, $override), '1');
                $this->fail('Unsafe test database configuration was accepted.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_mariadb_requires_opt_in_test_suffix_and_database_distinct_from_application_database(): void
    {
        $safe = ['driver' => 'mysql', 'database' => 'cotizadoracerca_test', 'host' => '127.0.0.1', 'port' => '3307', 'username' => 'cotizadoracerca_test_runner'];
        foreach ([
            [$safe, null, []],
            [array_replace($safe, ['database' => 'my_test', 'username' => 'other_test_runner']), '1', []],
            [$safe, '0', []],
            [$safe, '1', ['cotizadoracerca_test']],
            [$safe, '1', ['COTIZADORACERCA_TEST']],
            [array_replace($safe, ['database' => 'acerca_test', 'username' => 'acerca_test_runner']), '1', []],
        ] as [$config, $flag, $protected]) {
            try {
                TestDatabaseSafety::assertConnection($config, $flag, $protected);
                $this->fail('Unsafe MariaDB test database configuration was accepted.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_sqlite_files_and_connection_overrides_are_rejected_before_pdo(): void
    {
        foreach ([
            ['driver' => 'sqlite', 'database' => 'database/database.sqlite'],
            ['driver' => 'sqlite', 'database' => ':memory:', 'url' => 'sqlite:///local.sqlite'],
            ['driver' => 'sqlite', 'database' => ':memory:', 'read' => ['database' => 'local.sqlite']],
            ['driver' => 'sqlite', 'database' => ':memory:', 'options' => [1002 => 'ATTACH DATABASE']],
            ['driver' => 'pgsql', 'database' => 'cotizadoracerca_test'],
            [],
        ] as $config) {
            try {
                TestDatabaseSafety::assertConnection($config, '1');
                $this->fail('Unsafe connection was accepted.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }
}
