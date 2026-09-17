<?php

$usernames = json_decode(env('TELEGRAM_USERNAMES', '{}'), true);

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'chat_id' => env('TELEGRAM_CHAT_ID'),
    'message_thread_id' => env('TELEGRAM_MESSAGE_THREAD_ID'),
    'message_template' => env('TELEGRAM_MESSAGE_TEMPLATE', '{name} تسک "{task}" رو انجام داد ✅'),
    'usernames' => is_array($usernames) ? $usernames : [],
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    'webhook_endpoint' => env('TELEGRAM_WEBHOOK_ENDPOINT'),
];
