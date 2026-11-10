<?php

return [
    'login_attempt_retention_days' => (int) env('SECURITY_LOGIN_ATTEMPT_RETENTION_DAYS', 90),
    'login_attempt_schedule_prune' => (bool) env('SECURITY_LOGIN_ATTEMPT_SCHEDULE_PRUNE', true),
];
