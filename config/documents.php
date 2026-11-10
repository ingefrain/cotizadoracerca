<?php

return [
    'disk' => env('DOCUMENTS_DISK', 'local'),
    'directory' => 'documents',
    'max_size_kb' => (int) env('DOCUMENTS_MAX_SIZE_KB', 10240),
    'retention_days' => (int) env('DOCUMENTS_RETENTION_DAYS', 90),
    'orphan_retention_days' => (int) env('DOCUMENTS_ORPHAN_RETENTION_DAYS', 30),
    'audit_scan_limit' => (int) env('DOCUMENTS_AUDIT_SCAN_LIMIT', 500),
    'metadata_max_bytes' => 8192,
    'allowed_mime_types' => [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'text/plain' => ['txt', 'csv'],
        'text/csv' => ['csv'],
    ],
    'blocked_extensions' => [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
        'exe', 'dll', 'com', 'bat', 'cmd', 'ps1', 'sh', 'bash', 'zsh',
        'js', 'mjs', 'cjs', 'html', 'htm', 'svg', 'jar', 'msi', 'scr',
        'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py', 'rb', 'conf', 'config',
        'htaccess', 'htpasswd', 'env', 'ini', 'dll', 'so', 'dylib',
    ],
    'category_rules' => [
        'catalog_item.image' => [
            'max_size_kb' => 5120,
            'allowed_mime_types' => [
                'image/jpeg' => ['jpg', 'jpeg'],
                'image/png' => ['png'],
                'image/webp' => ['webp'],
            ],
        ],
        'user.avatar' => [
            'max_size_kb' => (int) env('USER_AVATAR_MAX_SIZE_KB', 5120),
            'allowed_mime_types' => [
                'image/jpeg' => ['jpg', 'jpeg'],
                'image/png' => ['png'],
                'image/webp' => ['webp'],
            ],
        ],
        'branding.logo' => [
            'max_size_kb' => (int) env('BRANDING_LOGO_MAX_SIZE_KB', 5120),
            'allowed_mime_types' => [
                'image/jpeg' => ['jpg', 'jpeg'],
                'image/png' => ['png'],
                'image/webp' => ['webp'],
            ],
        ],
        'organization.logo' => [
            'max_size_kb' => (int) env('ORGANIZATION_LOGO_MAX_SIZE_KB', 5120),
            'allowed_mime_types' => [
                'image/jpeg' => ['jpg', 'jpeg'],
                'image/png' => ['png'],
                'image/webp' => ['webp'],
            ],
        ],
    ],
    'category_labels' => [
        'catalog_item.image' => 'Imagen de elemento de catálogo',
        'user.avatar' => 'Foto de perfil',
        'branding.logo' => 'Logotipo del sistema',
        'organization.logo' => 'Logotipo de organización',
    ],
    'image_max_input_pixels' => (int) env('DOCUMENTS_IMAGE_MAX_INPUT_PIXELS', 16000000),
    'image_processing' => [
        'catalog_item.image' => [
            'enabled' => true,
            'format' => 'webp',
            'quality' => 85,
            'max_width' => 1600,
            'max_height' => 1600,
            'on_unsupported' => 'error',
        ],
        'user.avatar' => [
            'enabled' => true,
            'format' => 'webp',
            'quality' => (int) env('USER_AVATAR_WEBP_QUALITY', 82),
            'max_width' => (int) env('USER_AVATAR_MAX_WIDTH', 1024),
            'max_height' => (int) env('USER_AVATAR_MAX_HEIGHT', 1024),
            'on_unsupported' => 'error',
        ],
        'branding.logo' => [
            'enabled' => true,
            'format' => 'webp',
            'quality' => (int) env('BRANDING_LOGO_WEBP_QUALITY', 85),
            'max_width' => (int) env('BRANDING_LOGO_MAX_WIDTH', 1600),
            'max_height' => (int) env('BRANDING_LOGO_MAX_HEIGHT', 1600),
            'on_unsupported' => 'error',
        ],
        'organization.logo' => [
            'enabled' => true,
            'format' => 'webp',
            'quality' => (int) env('ORGANIZATION_LOGO_WEBP_QUALITY', 85),
            'max_width' => (int) env('ORGANIZATION_LOGO_MAX_WIDTH', 1600),
            'max_height' => (int) env('ORGANIZATION_LOGO_MAX_HEIGHT', 1600),
            'on_unsupported' => 'error',
        ],
    ],
];
