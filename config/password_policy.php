<?php

return [
    'minimum_length' => (int) env('PASSWORD_MIN_LENGTH', 8),
    'maximum_length' => (int) env('PASSWORD_MAX_LENGTH', 72),
    'require_mixed_case' => true,
    'require_number' => true,
];
