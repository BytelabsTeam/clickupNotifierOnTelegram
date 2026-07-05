<?php

$userNames = json_decode(env('CLICKUP_USER_NAMES', '{}'), true);

return [
    'api_token' => env('CLICKUP_API_TOKEN'),
    'team_id' => env('CLICKUP_TEAM_ID'),
    'webhook_secret' => env('CLICKUP_WEBHOOK_SECRET'),
    'webhook_endpoint' => env('CLICKUP_WEBHOOK_ENDPOINT'),
    'done_statuses' => array_values(array_filter(array_map(
        static fn (string $status): string => mb_strtolower(trim($status)),
        explode(',', (string) env('CLICKUP_DONE_STATUSES', 'complete,done,تکمیل'))
    ))),
    'user_names' => is_array($userNames) ? $userNames : [],
    'space_id' => env('CLICKUP_SPACE_ID'),
    'folder_id' => env('CLICKUP_FOLDER_ID'),
    'list_id' => env('CLICKUP_LIST_ID'),
    'poll_interval_seconds' => (int) env('CLICKUP_POLL_INTERVAL', 10),
    'poll_lookback_minutes' => (int) env('CLICKUP_POLL_LOOKBACK_MINUTES', 30),
    'cron_token' => env('CLICKUP_CRON_TOKEN'),
];
