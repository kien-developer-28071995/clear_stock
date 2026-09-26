<?php

// Every email the app sends is logged in email_logs (metadata, no body). See App\Listeners\LogEmails.
return [
    'retention_days' => (int) env('EMAIL_LOG_RETENTION_DAYS', 180),
];
