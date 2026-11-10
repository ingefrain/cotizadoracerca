<?php

namespace App\Console\Commands;

use App\Services\Outbox\OutboxDispatcher;
use App\Services\Outbox\OutboxTransportResolver;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SendOutboxTestTelegram extends Command
{
    protected $signature = 'outbox:test-telegram';

    protected $description = 'Encola un mensaje de prueba para validar el transporte Telegram';

    public function handle(OutboxDispatcher $outbox, OutboxTransportResolver $transports): int
    {
        if (! (bool) config('services.telegram.enabled')) {
            $this->components->error('Telegram está deshabilitado. No se creó ningún mensaje.');

            return self::FAILURE;
        }

        if (! is_string(config('services.telegram.bot_token')) || trim((string) config('services.telegram.bot_token')) === '') {
            $this->components->error('La configuración de Telegram está incompleta. No se creó ningún mensaje.');

            return self::FAILURE;
        }

        try {
            $defaultChatId = (string) config('services.telegram.default_chat_id', '');
            $transports->resolve('telegram')->normalizeRecipient($defaultChatId);
            $message = $outbox->telegram(
                'Prueba de Telegram desde Cotizador Acerca.',
                category: 'administrative.test',
            );
        } catch (InvalidArgumentException) {
            $this->components->error('La configuración o el destino autorizado de Telegram no es válido. No se creó ningún mensaje.');

            return self::FAILURE;
        }

        $this->components->info("Mensaje encolado: {$message->uuid}");

        return self::SUCCESS;
    }
}
