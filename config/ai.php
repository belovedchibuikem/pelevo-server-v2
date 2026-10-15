<?php

return [
    'daily_job_cap' => (int) env('AI_DAILY_JOB_CAP', 10),
    // Local WAMP has no queue worker. The job runs in the request, then the app reads the result.
    'process_inline' => filter_var(env('AI_PROCESS_INLINE', env('APP_ENV', 'production') === 'local'), FILTER_VALIDATE_BOOLEAN),
];
