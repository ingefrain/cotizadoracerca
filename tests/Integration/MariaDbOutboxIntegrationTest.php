<?php

namespace Tests\Integration;

use App\Models\OutboxMessage;
use App\Services\Outbox\OutboxDispatcher;
use App\Services\Outbox\OutboxProcessor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

class MariaDbOutboxIntegrationTest extends TestCase
{
    private const CONNECTION = 'outbox_mariadb_test';

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('OUTBOX_MARIADB_TEST') !== '1') {
            $this->markTestSkipped('Set OUTBOX_MARIADB_TEST=1 to run against the isolated MariaDB test database.');
        }
        if (! getenv('OUTBOX_MARIADB_DATABASE') || ! getenv('OUTBOX_MARIADB_USERNAME') || ! getenv('OUTBOX_MARIADB_PASSWORD')) {
            throw new \RuntimeException('The dedicated MariaDB test database and runner credentials are required.');
        }

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
        $appDatabase = (string) config('database.connections.'.config('database.default').'.database');
        TestDatabaseSafety::assertConnection($connection, getenv('OUTBOX_MARIADB_TEST') ?: null, [$appDatabase]);
        config([
            'database.connections.'.self::CONNECTION => $connection,
            'database.default' => self::CONNECTION,
        ]);
        app('db')->purge(self::CONNECTION);
        Artisan::call('migrate:fresh', ['--database' => self::CONNECTION, '--force' => true]);
    }

    public function test_mariadb_uses_persistent_atomic_state_to_prevent_repeated_delivery(): void
    {
        Mail::fake();
        $message = app(OutboxDispatcher::class)->email('mariadb@example.test', [
            'subject' => 'Prueba aislada',
            'message' => 'Prueba de estado MariaDB.',
        ]);

        $this->assertTrue(app(OutboxProcessor::class)->process($message->id));
        $this->assertFalse(app(OutboxProcessor::class)->process($message->id));
        $this->assertSame(OutboxMessage::SENT, $message->fresh()->status);
        $this->assertSame(1, $message->attemptsHistory()->count());
    }

    public function test_mariadb_reclaims_an_expired_lease_without_reusing_its_attempt_record(): void
    {
        Mail::fake();
        $message = app(OutboxDispatcher::class)->email('mariadb@example.test', [
            'subject' => 'Prueba de lease',
            'message' => 'Recuperación de lease expirado.',
        ]);
        $token = (string) Str::uuid();
        $message->forceFill([
            'status' => OutboxMessage::PROCESSING,
            'attempts' => 1,
            'lease_token' => $token,
            'lease_expires_at' => now()->subSecond(),
        ])->save();
        $message->attemptsHistory()->create([
            'attempt_number' => 1,
            'lease_token' => $token,
            'started_at' => now()->subMinutes(6),
        ]);

        $this->assertTrue(app(OutboxProcessor::class)->process($message->id));
        $this->assertSame(OutboxMessage::SENT, $message->fresh()->status);
        $this->assertSame(2, $message->attemptsHistory()->count());
        $this->assertDatabaseHas('outbox_attempts', [
            'outbox_message_id' => $message->id,
            'attempt_number' => 1,
            'result' => 'abandoned',
        ]);
    }

    public function test_json_column_semantics_and_nullable_idempotency_invariants(): void
    {
        $version = (string) DB::scalar('SELECT VERSION()');
        $payloadType = strtolower((string) DB::getSchemaBuilder()->getColumnType('outbox_messages', 'payload'));
        if (stripos($version, 'mariadb') !== false) {
            // MariaDB implements Laravel's JSON declaration as LONGTEXT plus JSON_VALID CHECK.
            $this->assertSame('longtext', $payloadType);
            $checks = DB::select("SELECT cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = 'outbox_messages' AND tc.CONSTRAINT_TYPE = 'CHECK'");
            $this->assertNotEmpty($checks);
            $clauses = implode(' ', array_map(fn (object $check): string => (string) $check->CHECK_CLAUSE, $checks));
            $this->assertStringContainsString('json_valid', strtolower($clauses));
            $this->assertStringContainsString('payload', strtolower($clauses));
        } else {
            // MySQL exposes JSON as a native column type and rejects invalid JSON values.
            $this->assertSame('json', $payloadType);
        }

        $indexes = collect(DB::select('SHOW INDEX FROM outbox_messages'))->pluck('Key_name')->all();
        $this->assertContains('outbox_messages_idempotency_key_unique', $indexes);
        $this->assertContains('outbox_ready_idx', $indexes);
        $this->assertContains('outbox_lease_idx', $indexes);

        $dispatcher = app(OutboxDispatcher::class);
        $first = $dispatcher->email('first@example.test', ['subject' => 'A', 'message' => 'A']);
        $second = $dispatcher->email('second@example.test', ['subject' => 'B', 'message' => 'B']);
        $this->assertSame(['subject' => 'A', 'message' => 'A'], $first->fresh()->payload);
        $this->assertNull($first->idempotency_key);
        $this->assertNull($second->idempotency_key);
        $this->assertNotSame($first->id, $second->id);

        try {
            DB::table('outbox_messages')->insert([
                'uuid' => (string) Str::uuid(),
                'category' => 'transactional',
                'channel' => 'email',
                'recipient' => 'invalid-json@example.test',
                'recipient_hash' => hash('sha256', 'invalid-json@example.test'),
                'template' => 'generic.email.v1',
                'payload' => '{invalid JSON',
                'available_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('The database accepted an invalid JSON payload.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_two_independent_processes_cannot_claim_the_same_mariadb_message(): void
    {
        $message = app(OutboxDispatcher::class)->email('race@example.test', ['subject' => 'Concurrente', 'message' => 'Reclamación concurrente.']);
        $gate = tempnam(sys_get_temp_dir(), 'outbox-gate-');
        if ($gate === false) {
            $this->fail('Could not create the local worker barrier.');
        }
        unlink($gate);
        $script = base_path('tests/Support/mariadb_outbox_worker.php');
        $workerEnvironment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:'];
        $workers = [
            new Process([PHP_BINARY, $script, (string) $message->id, $gate], base_path(), $workerEnvironment),
            new Process([PHP_BINARY, $script, (string) $message->id, $gate], base_path(), $workerEnvironment),
        ];
        foreach ($workers as $worker) {
            $worker->start();
        }
        touch($gate);
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
        }
        unlink($gate);

        $this->assertSame(['claimed', 'skipped'], collect($workers)->map(fn (Process $worker) => $worker->getOutput())->sort()->values()->all());
        $this->assertSame(OutboxMessage::SENT, $message->fresh()->status);
        $this->assertSame(1, $message->attemptsHistory()->count());
    }
}
