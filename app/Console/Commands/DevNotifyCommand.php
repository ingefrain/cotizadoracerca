<?php

namespace App\Console\Commands;

use App\Services\Outbox\OutboxTransportResult;
use App\Services\TelegramClient;
use Illuminate\Console\Command;

final class DevNotifyCommand extends Command
{
    protected $signature = 'dev:notify {--project=} {--message=} {--title=Codex}';

    protected $description = 'Send a local development notice via Telegram';

    public function handle(TelegramClient $client): int
    {
        if (! app()->environment('local')) {
            return $this->failNotice();
        }

        $project = trim((string) $this->option('project'));
        $message = trim((string) $this->option('message'));
        $title = trim((string) $this->option('title'));
        $text = implode("\n\n", array_filter([
            $title,
            $project === '' ? '' : 'Proyecto: '.$project,
            $message,
        ], fn (string $part): bool => $part !== ''));

        if ($message === '' || ! mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > 1000) {
            return $this->failNotice();
        }

        $result = $client->send((string) config('services.telegram.default_chat_id'), $text);
        if ($result->status !== OutboxTransportResult::ACCEPTED) {
            return $this->failNotice();
        }

        $this->info('Notificación enviada.');

        return self::SUCCESS;
    }

    private function failNotice(): int
    {
        $this->error('No fue posible enviar la notificación.');

        return self::FAILURE;
    }
}
