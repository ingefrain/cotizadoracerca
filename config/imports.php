<?php

return [
    'max_rows' => (int) env('IMPORT_MAX_ROWS', 5000),
    'max_size_kb' => (int) env('IMPORT_MAX_SIZE_KB', 2048),
    'max_seconds' => (int) env('IMPORT_MAX_SECONDS', 20),
    'retention_hours' => (int) env('IMPORT_SOURCE_RETENTION_HOURS', 24),
];
