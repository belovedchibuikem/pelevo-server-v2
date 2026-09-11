<?php

return [
    'admin_disk' => env('ADMIN_EXPORT_DISK', 'local'),
    'user_disk' => env('DATA_EXPORT_DISK', 'local'),
    'retention_hours' => (int) env('EXPORT_RETENTION_HOURS', 24),
];
