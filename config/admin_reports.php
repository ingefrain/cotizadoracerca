<?php

use App\Reports\OrganizationsReport;
use App\Reports\UsersReport;

return [
    'export_max_rows' => (int) env('REPORT_EXPORT_MAX_ROWS', 10000),

    'reports' => [
        'users' => [
            'title' => 'Usuarios',
            'description' => 'Consulta usuarios, estado, roles y actividad.',
            'route' => 'admin.reports.users',
            'permission' => 'users.view',
            'report' => UsersReport::class,
            'filename' => 'usuarios',
        ],
        'organizations' => [
            'title' => 'Organizaciones',
            'description' => 'Consulta organizaciones, estado y número de sedes.',
            'route' => 'admin.reports.organizations',
            'permission' => 'organizations.view',
            'report' => OrganizationsReport::class,
            'filename' => 'organizaciones',
        ],
    ],
];
