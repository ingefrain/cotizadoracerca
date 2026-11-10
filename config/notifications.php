<?php

use App\Services\Outbox\EmailOutboxTransport;
use App\Services\Outbox\TelegramOutboxTransport;

return [
    'transports' => [
        'email' => [
            'enabled' => true,
            'label' => 'Correo electrónico',
            'class' => EmailOutboxTransport::class,
        ],
        'telegram' => [
            'enabled' => (bool) env('TELEGRAM_ENABLED', false),
            'label' => 'Telegram',
            'class' => TelegramOutboxTransport::class,
        ],
    ],
    'branding' => [
        'name' => env('NOTIFICATION_BRAND_NAME', env('APP_NAME', 'Cotizador Acerca')),
        'logo_url' => env('NOTIFICATION_BRAND_LOGO_URL'),
        'greeting' => env('NOTIFICATION_GREETING', 'Hola'),
        'footer' => env('NOTIFICATION_FOOTER', 'Mensaje automático. Si tienes preguntas, contacta al equipo de soporte.'),
        'primary_color' => env('NOTIFICATION_PRIMARY_COLOR', '#126c72'),
    ],
];
