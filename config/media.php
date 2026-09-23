<?php

return [
    'upload_disk' => env('MEDIA_UPLOAD_DISK', 'local'),
    'max_reel_bytes' => (int) env('MEDIA_MAX_REEL_BYTES', 209715200),
    'max_reel_duration_ms' => (int) env('MEDIA_MAX_REEL_DURATION_MS', 180000),
    'upload_url_ttl_minutes' => (int) env('MEDIA_UPLOAD_URL_TTL_MINUTES', 15),
    'process_inline' => filter_var(env('MEDIA_PROCESS_INLINE', env('APP_ENV') === 'local'), FILTER_VALIDATE_BOOL),
    'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),
    'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
    'qualified_view_ms' => (int) env('REEL_QUALIFIED_VIEW_MS', 3000),
    'view_window_minutes' => (int) env('REEL_VIEW_WINDOW_MINUTES', 60),
    'qualified_views_per_window' => (int) env('REEL_QUALIFIED_VIEWS_PER_WINDOW', 1),
];
