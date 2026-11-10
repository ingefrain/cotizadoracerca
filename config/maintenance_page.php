<?php

return [
    'message' => env('MAINTENANCE_PAGE_MESSAGE', 'Estamos realizando una actualización.'),
    'secondary_message' => env('MAINTENANCE_PAGE_SECONDARY_MESSAGE', 'El servicio estará disponible nuevamente en breve.'),
    'logo_url' => env('MAINTENANCE_PAGE_LOGO_URL'),
    'refresh_seconds' => max(0, (int) env('MAINTENANCE_PAGE_REFRESH_SECONDS', 0)),
];
