<?php

return [
    'expiry_days' => (int) env('CLAIM_EXPIRY_DAYS', 14),
    'email_code_expiry_minutes' => (int) env('CLAIM_EMAIL_CODE_EXPIRY_MINUTES', 15),
    'max_attempts' => (int) env('CLAIM_CODE_MAX_ATTEMPTS', 5),
];
