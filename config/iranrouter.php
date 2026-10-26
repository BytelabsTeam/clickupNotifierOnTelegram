<?php

return [
    'api_key' => env('IRANROUTER_API_KEY'),
    'base_url' => rtrim((string) env('IRANROUTER_BASE_URL', 'https://www.iranrouter.net/api/v1'), '/'),
    'model' => env('IRANROUTER_MODEL', 'openai/gpt-6-luna'),
    'timeout' => (int) env('IRANROUTER_TIMEOUT', 60),
];
