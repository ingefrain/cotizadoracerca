<?php

namespace App\Console\Commands;

use App\Services\Outbox\OutboxDispatcher;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SendOutboxTestEmail extends Command
{
    protected $signature = 'outbox:test-email {recipient : Destination email address}';

    protected $description = 'Crea un mensaje de prueba para validar la entrega transaccional';

    public function handle(OutboxDispatcher $outbox): int
    {
        try {
            $message = $outbox->email((string) $this->argument('recipient'), [
                'subject' => 'Prueba de correo transaccional',
                'heading' => 'Entrega de prueba',
                'message' => 'La infraestructura de correo transaccional está procesando mensajes correctamente.',
            ], 'administrative.test');
        } catch (InvalidArgumentException) {
            $this->components->error('El destinatario o el contenido de prueba no es válido.');

            return self::INVALID;
        }

        $this->components->info("Mensaje creado: {$message->uuid}");

        return self::SUCCESS;
    }
}
