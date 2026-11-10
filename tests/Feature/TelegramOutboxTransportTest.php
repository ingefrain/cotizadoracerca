<?php

namespace Tests\Feature;

use App\Models\OutboxMessage;
use App\Services\Outbox\OutboxDispatcher;
use App\Services\Outbox\OutboxProcessor;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class TelegramOutboxTransportTest extends TestCase
{
    use InteractsWithBaseAdmin;
    use RefreshDatabase;

    private const TOKEN = 'telegram-test-token-not-a-secret';

    private const CHAT_ID = '987654321';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'services.telegram.enabled' => true,
            'services.telegram.bot_token' => self::TOKEN,
            'services.telegram.default_chat_id' => self::CHAT_ID,
            'services.telegram.allowed_chat_ids' => [self::CHAT_ID],
            'services.telegram.connect_timeout' => 5,
            'services.telegram.timeout' => 10,
            'notifications.transports.telegram.enabled' => true,
        ]);
    }

    public function test_disabled_telegram_is_omitted_from_available_transports_and_does_not_enqueue(): void
    {
        config(['services.telegram.enabled' => false, 'notifications.transports.telegram.enabled' => false]);

        try {
            app(OutboxDispatcher::class)->telegram('Prueba deshabilitada.');
            $this->fail('Telegram dispatch should fail while the channel is disabled.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('outbox_messages', 0);
        }
    }

    public function test_test_command_rejects_missing_configuration_without_enqueuing_or_printing_secrets(): void
    {
        config(['services.telegram.bot_token' => null]);

        $this->artisan('outbox:test-telegram')
            ->expectsOutputToContain('configuración de Telegram está incompleta')
            ->assertExitCode(1);

        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_test_command_enqueues_only_and_does_not_call_telegram_directly(): void
    {
        $this->artisan('outbox:test-telegram')
            ->expectsOutputToContain('Mensaje encolado:')
            ->assertExitCode(0);

        $this->assertDatabaseHas('outbox_messages', [
            'channel' => 'telegram',
            'template' => 'telegram.text.v1',
            'recipient_hash' => hash('sha256', self::CHAT_ID),
            'category' => 'administrative.test',
            'status' => OutboxMessage::PENDING,
        ]);
        Http::assertNothingSent();
    }

    public function test_telegram_template_rejects_empty_unknown_and_oversized_payloads(): void
    {
        foreach ([
            ['text' => '   '],
            ['text' => "\xC3\x28"],
            ['text' => 'válido', 'parse_mode' => 'HTML'],
            ['text' => str_repeat('a', 4097)],
        ] as $payload) {
            try {
                app(OutboxDispatcher::class)->dispatch('telegram', 'telegram.text.v1', self::CHAT_ID, $payload);
                $this->fail('Invalid Telegram payload was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('outbox_messages', 0);
            }
        }
    }

    public function test_destination_must_belong_to_the_configured_allowlist(): void
    {
        try {
            app(OutboxDispatcher::class)->telegram('Texto no autorizado.', '123456789');
            $this->fail('An unlisted chat identifier was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('outbox_messages', 0);
        }
    }

    public function test_telegram_uses_send_message_and_accepts_only_a_valid_success_structure(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 2468, 'chat' => ['id' => (int) self::CHAT_ID]],
        ], 200)]);
        $message = app(OutboxDispatcher::class)->telegram('Prueba segura.');

        $this->assertTrue(app(OutboxProcessor::class)->process($message->id));
        $attempt = $message->attemptsHistory()->firstOrFail();
        $this->assertSame(OutboxMessage::SENT, $message->fresh()->status);
        $this->assertSame('accepted', $attempt->result);
        $this->assertSame('2468', $attempt->provider_reference);
        $this->assertSame(['text' => 'Prueba segura.'], $message->fresh()->payload);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request->data() === ['chat_id' => self::CHAT_ID, 'text' => 'Prueba segura.']
            && ! array_key_exists('parse_mode', $request->data()));
    }

    public function test_http_200_with_ok_false_is_permanent_and_response_text_is_not_persisted(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response([
            'ok' => false,
            'error_code' => 403,
            'description' => 'rejected token='.self::TOKEN,
        ], 200)]);
        $message = app(OutboxDispatcher::class)->telegram('Prueba rechazada.');

        $this->assertFalse(app(OutboxProcessor::class)->process($message->id));
        $message->refresh();
        $this->assertSame(OutboxMessage::FAILED, $message->status);
        $this->assertSame('telegram.request_rejected', $message->last_error_code);
        $this->assertStringNotContainsString(self::TOKEN, json_encode([$message->getAttributes(), $message->attemptsHistory->toArray()]));
    }

    public function test_invalid_json_success_response_is_uncertain_and_not_retried(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response('{invalid', 200)]);
        $message = app(OutboxDispatcher::class)->telegram('Respuesta ilegible.');

        $this->assertFalse(app(OutboxProcessor::class)->process($message->id));
        $this->assertSame(OutboxMessage::UNCERTAIN, $message->fresh()->status);
        $this->assertFalse(app(OutboxProcessor::class)->process($message->id));
        $this->assertSame(1, $message->attemptsHistory()->count());
    }

    public function test_http_400_401_and_403_are_permanent_while_http_500_is_retryable(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::sequence()
            ->push(['ok' => false, 'error_code' => 400], 400)
            ->push(['ok' => false, 'error_code' => 401], 401)
            ->push(['ok' => false, 'error_code' => 403], 403)
            ->push(['ok' => false, 'error_code' => 500], 500)]);

        $statuses = [];
        for ($index = 0; $index < 4; $index++) {
            $message = app(OutboxDispatcher::class)->telegram('Clasificación HTTP '.$index);
            app(OutboxProcessor::class)->process($message->id);
            $statuses[] = [$message->fresh()->status, $message->attemptsHistory()->firstOrFail()->result];
        }

        $this->assertSame([
            [OutboxMessage::FAILED, 'permanent_failure'],
            [OutboxMessage::FAILED, 'permanent_failure'],
            [OutboxMessage::FAILED, 'permanent_failure'],
            [OutboxMessage::PENDING, 'retryable_failure'],
        ], $statuses);
    }

    public function test_http_429_uses_retry_after_without_sleeping(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response([
            'ok' => false,
            'error_code' => 429,
            'parameters' => ['retry_after' => 73],
        ], 429)]);
        $message = app(OutboxDispatcher::class)->telegram('Prueba limitada.');

        $this->assertFalse(app(OutboxProcessor::class)->process($message->id));
        $this->assertSame(OutboxMessage::PENDING, $message->fresh()->status);
        $this->assertSame(now()->addSeconds(73)->timestamp, $message->fresh()->available_at->timestamp);
        $this->assertSame('retryable_failure', $message->attemptsHistory()->firstOrFail()->result);
    }

    public function test_connect_failure_before_sending_is_retryable(): void
    {
        $connectionRequest = new Request('POST', 'https://api.telegram.org/bot'.self::TOKEN.'/sendMessage');
        Http::fake(function () use ($connectionRequest) {
            throw new ConnectionException('connection failed', 0, new ConnectException('connection failed', $connectionRequest));
        });
        $notSent = app(OutboxDispatcher::class)->telegram('Fallo de conexión.');
        $this->assertFalse(app(OutboxProcessor::class)->process($notSent->id));
        $this->assertSame(OutboxMessage::PENDING, $notSent->fresh()->status);
        $this->assertSame('retryable_failure', $notSent->attemptsHistory()->firstOrFail()->result);

    }

    public function test_timeout_without_connect_failure_is_uncertain_and_sanitized(): void
    {
        $connectionRequest = new Request('POST', 'https://api.telegram.org/bot'.self::TOKEN.'/sendMessage');
        Http::fake(function () use ($connectionRequest) {
            throw new ConnectionException('timeout token='.self::TOKEN, 0, new RequestException('timeout', $connectionRequest));
        });
        $possiblySent = app(OutboxDispatcher::class)->telegram('Tiempo agotado.');
        $this->assertFalse(app(OutboxProcessor::class)->process($possiblySent->id));
        $this->assertSame(OutboxMessage::UNCERTAIN, $possiblySent->fresh()->status);
        $this->assertStringNotContainsString(self::TOKEN, (string) $possiblySent->fresh()->last_error_message);
    }

    public function test_success_and_error_records_never_store_the_bot_token(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response([
            'ok' => false,
            'error_code' => 401,
            'description' => 'invalid token='.self::TOKEN,
        ], 401)]);
        $message = app(OutboxDispatcher::class)->telegram('Credencial no persistente.');
        app(OutboxProcessor::class)->process($message->id);

        $stored = json_encode([
            $message->fresh()->getAttributes(),
            $message->attemptsHistory()->get()->toArray(),
        ]);
        $this->assertStringNotContainsString(self::TOKEN, (string) $stored);
        $this->assertSame('telegram.authentication_rejected', $message->fresh()->last_error_code);
    }

    public function test_telegram_panel_masks_chat_id_and_keeps_message_text_hidden(): void
    {
        $admin = $this->adminUser();
        $message = app(OutboxDispatcher::class)->telegram('Texto privado de prueba.');

        $this->actingAsAdmin($admin)->get(route('admin.notifications.index'))
            ->assertOk()->assertSee('Telegram')->assertSee('******4321')->assertDontSee(self::CHAT_ID)
            ->assertDontSee('Texto privado de prueba.');
        $this->actingAsAdmin($admin)->get(route('admin.notifications.show', $message))
            ->assertOk()->assertSee('Telegram')->assertSee('******4321')->assertDontSee(self::CHAT_ID)
            ->assertDontSee('Texto privado de prueba.');
    }

    public function test_telegram_idempotency_and_transaction_rollback_are_preserved(): void
    {
        $dispatcher = app(OutboxDispatcher::class);
        $first = $dispatcher->telegram('Idempotente.', idempotencyKey: 'telegram-operation-1');
        $same = $dispatcher->telegram('Idempotente.', idempotencyKey: 'telegram-operation-1');
        $this->assertSame($first->id, $same->id);

        try {
            $dispatcher->telegram('Otro texto.', idempotencyKey: 'telegram-operation-1');
            $this->fail('The Telegram idempotency key was reused with changed content.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('outbox_messages', 1);
        }

        DB::beginTransaction();
        $rolledBack = $dispatcher->telegram('Rollback.');
        DB::rollBack();
        $this->assertDatabaseMissing('outbox_messages', ['id' => $rolledBack->id]);
    }

    public function test_uncertain_telegram_messages_cannot_be_manually_retried(): void
    {
        $admin = $this->adminUser();
        $message = app(OutboxDispatcher::class)->telegram('Resultado incierto.');
        $message->forceFill(['status' => OutboxMessage::UNCERTAIN, 'processed_at' => now()])->save();

        $this->actingAsAdmin($admin)->post(route('admin.notifications.retry', $message))
            ->assertSessionHasErrors('status');
        $this->assertSame(OutboxMessage::UNCERTAIN, $message->fresh()->status);
    }
}
