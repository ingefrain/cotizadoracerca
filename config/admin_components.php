<?php

return [
    'enabled' => env('ADMIN_COMPONENTS_ENABLED', true),

    'cards' => [
        ['key' => 'charts', 'title' => 'Gráficas', 'description' => 'Visualización reutilizable de datos mediante Chart.js.', 'route' => 'admin.components.charts'],
        ['key' => 'utilities', 'title' => 'Utilidades', 'description' => 'Controles y feedback reutilizables para interacciones administrativas.', 'route' => 'admin.components.utilities'],
        ['key' => 'confirmations', 'title' => 'Confirmaciones y avisos', 'description' => 'Diálogos de confirmación y mensajes de estado reutilizables.', 'route' => 'admin.components.confirmations'],
        ['key' => 'markdown', 'title' => 'Markdown', 'description' => 'Editor Markdown con toolbar y vista previa segura.', 'route' => 'admin.components.markdown'],
        ['key' => 'dropzone', 'title' => 'Dropzone', 'description' => 'Carga por selección o arrastre con preview y validación visual.', 'route' => 'admin.components.dropzone'],
        ['key' => 'password', 'title' => 'Contraseñas', 'description' => 'Campos de contraseña, visibilidad y requisitos en tiempo real.', 'route' => 'admin.components.password'],
        ['key' => 'modal', 'title' => 'Modales', 'description' => 'Modal accesible con foco, Escape y cierre controlado.', 'route' => 'admin.components.modal'],
        ['key' => 'multi-select', 'title' => 'Selector de roles', 'description' => 'Selector múltiple con búsqueda y etiquetas.', 'route' => 'admin.components.multi-select'],
    ],
];
