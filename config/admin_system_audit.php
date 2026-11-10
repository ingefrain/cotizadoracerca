<?php

return [
    'cards' => [
        [
            'title' => 'Seguridad de acceso',
            'description' => 'Consulta inicios de sesión, intentos fallidos y bloqueos recientes.',
            'action' => 'Consultar seguridad de acceso',
            'route' => 'admin.login-security.index',
            'permission' => 'security.login.view',
        ],
        [
            'title' => 'Actividad administrativa',
            'description' => 'Consulta quién realizó cambios administrativos y qué modificó.',
            'action' => 'Consultar actividad administrativa',
            'route' => 'admin.audit.index',
            'permission' => 'audit.view',
        ],
        [
            'title' => 'Permisos del sistema',
            'description' => 'Consulta el catálogo técnico de capacidades y su asignación mediante roles.',
            'action' => 'Consultar permisos del sistema',
            'route' => 'admin.permissions.index',
            'permission' => 'permissions.view',
        ],
        [
            'title' => 'Entregas',
            'description' => 'Consulta mensajes transaccionales, reintentos y fallos de entrega.',
            'action' => 'Consultar entregas',
            'route' => 'admin.notifications.index',
            'permission' => 'notifications.view',
        ],
        [
            'title' => 'Estado del sistema',
            'description' => 'Consulta indicadores técnicos básicos de la aplicación.',
            'action' => 'Consultar estado del sistema',
            'route' => 'admin.system-status.index',
            'permission' => 'system.audit.view',
        ],
    ],
];
