<?php

return [
    'base_url' => env('ANALYTICS_SERVICE_URL', 'http://analytics:8000'),
    'token' => env('ANALYTICS_SERVICE_TOKEN'),
    'timeout_seconds' => (int) env('ANALYTICS_SERVICE_TIMEOUT', 15),
];
