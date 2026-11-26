<?php

return [
    'api_key' => env('IRANROUTER_API_KEY'),
    // Use non-www; www.iranrouter.net 301-redirects and Guzzle turns POST into GET (405).
    'base_url' => rtrim((string) env('IRANROUTER_BASE_URL', 'https://iranrouter.net/api/v1'), '/'),
    'model' => env('IRANROUTER_MODEL', 'openai/gpt-6-luna'),
    'timeout' => (int) env('IRANROUTER_TIMEOUT', 60),

    // Optional HTTP(S) proxy for IranRouter / AI API calls only.
    'proxy' => [
        'host' => env('AI_PROXY_HOST'),
        'port' => env('AI_PROXY_PORT'),
        'user' => env('AI_PROXY_USER'),
        'pass' => env('AI_PROXY_PASS'),
    ],
];
