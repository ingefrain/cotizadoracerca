<?php

namespace App\Services\Outbox;

use App\Contracts\OutboxTransport;
use InvalidArgumentException;

final class OutboxTransportResolver
{
    public function resolve(string $channel): OutboxTransport
    {
        $settings = config("notifications.transports.{$channel}");
        $class = is_array($settings) && ($settings['enabled'] ?? false) ? ($settings['class'] ?? null) : null;
        if (! is_string($class) || ! class_exists($class)) {
            throw new InvalidArgumentException('The requested notification transport is unavailable.');
        }
        $transport = app($class);
        if (! $transport instanceof OutboxTransport) {
            throw new InvalidArgumentException('The configured notification transport is invalid.');
        }

        return $transport;
    }

    /** @return array<string, string> */
    public function enabledChannels(): array
    {
        $channels = [];
        foreach (config('notifications.transports', []) as $channel => $settings) {
            if (is_array($settings) && ($settings['enabled'] ?? false) && is_string($channel)) {
                $channels[$channel] = (string) ($settings['label'] ?? ucfirst($channel));
            }
        }

        return $channels;
    }
}
