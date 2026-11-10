<?php

namespace App\Mail;

use App\Contracts\OutboxEmailSender;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

class LaravelOutboxEmailSender implements OutboxEmailSender
{
    public function send(string $recipient, array $payload): void
    {
        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$mailer}.transport");
        if (! app()->environment('testing') && in_array($transport, ['', 'log', 'array', 'failover', 'roundrobin'], true)) {
            throw new InvalidArgumentException('Email transport is not enabled for delivery.');
        }

        Mail::to($recipient)->send(new TransactionalEmail($payload));
    }
}
