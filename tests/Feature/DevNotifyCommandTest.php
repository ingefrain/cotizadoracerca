<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DevNotifyCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('env', 'local');
        config([
            'services.telegram.enabled' => true,
            'services.telegram.bot_token' => 'fake-test-credential',
            'services.telegram.default_chat_id' => '123456',
            'services.telegram.allowed_chat_ids' => ['123456'],
        ]);
        Http::preventStrayRequests();
    }

    public function test_local_command_sends_plain_text_without_database_queries(): void
    {
        DB::shouldReceive('connection')->never();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1, 'chat' => ['id' => 123456]]])]);

        $this->artisan('dev:notify', ['--project' => 'Cotizador Acerca', '--message' => 'Aviso'])
            ->expectsOutput('Notificación enviada.')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->data() === [
            'chat_id' => '123456', 'text' => "Codex\n\nProyecto: Cotizador Acerca\n\nAviso",
        ]);
    }

    #[DataProvider('rejections')]
    public function test_invalid_requests_do_not_contact_telegram(string $env, array $config, string $message): void
    {
        $this->app->instance('env', $env);
        config($config);
        Http::fake();

        $this->artisan('dev:notify', ['--message' => $message])
            ->expectsOutput('No fue posible enviar la notificación.')->assertFailed();

        Http::assertNothingSent();
    }

    public static function rejections(): array
    {
        return [
            ['production', [], 'Aviso'],
            ['local', ['services.telegram.enabled' => false], 'Aviso'],
            ['local', ['services.telegram.bot_token' => null], 'Aviso'],
            ['local', ['services.telegram.default_chat_id' => null], 'Aviso'],
            ['local', ['services.telegram.allowed_chat_ids' => []], 'Aviso'],
            ['local', [], '   '],
            ['local', [], str_repeat('x', 1001)],
        ];
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failure_is_reported_without_raw_details(?int $status): void
    {
        Http::fake(['*' => function () use ($status) {
            if ($status === null) {
                throw new ConnectionException('Timeout fake-test-credential');
            }

            return Http::response(['description' => 'fake-test-credential'], $status);
        }]);

        $this->artisan('dev:notify', ['--message' => 'Aviso'])
            ->expectsOutput('No fue posible enviar la notificación.')
            ->doesntExpectOutputToContain('fake-test-credential')->assertFailed();
    }

    public static function providerFailures(): array
    {
        return [[401], [429], [503], [null]];
    }
}
