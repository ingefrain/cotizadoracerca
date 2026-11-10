<?php

namespace App\Console\Commands;

use App\Jobs\ProcessOutboxMessage;
use App\Models\OutboxMessage;
use App\Services\Outbox\OutboxProcessor;
use Illuminate\Console\Command;
use Throwable;

class ProcessOutbox extends Command
{
    protected $signature = 'outbox:process {--limit=100 : Máximo de mensajes por ejecución} {--sync : Procesar en esta ejecución, sin despachar jobs}';

    protected $description = 'Procesa o despacha mensajes transaccionales pendientes';

    public function handle(OutboxProcessor $processor): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->components->error('El límite debe ser un entero entre 1 y 1000.');

            return self::INVALID;
        }

        $ids = OutboxMessage::query()->ready()->orderBy('created_at')->limit($limit)->pluck('id');
        $processed = 0;
        foreach ($ids as $id) {
            try {
                if ($this->option('sync')) {
                    $processor->process((int) $id);
                } else {
                    ProcessOutboxMessage::dispatch((int) $id);
                }
                $processed++;
            } catch (Throwable) {
                $this->components->warn('No se pudo despachar uno de los mensajes; seguirá disponible para otra ejecución.');
            }
        }

        $this->components->info("Mensajes procesados o despachados: {$processed}");

        return self::SUCCESS;
    }
}
