<?php

// Team identity (members, GitHub logins, admin) lives in the team_members table.
// Env values here are connection settings and message templates only.
return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'chat_id' => env('TELEGRAM_CHAT_ID'),
    'message_thread_id' => env('TELEGRAM_MESSAGE_THREAD_ID'),
    'message_template' => env('TELEGRAM_MESSAGE_TEMPLATE', '{name} تسک "{task}" رو انجام داد ✅'),
    'commit_message_template' => env(
        'TELEGRAM_COMMIT_MESSAGE_TEMPLATE',
        "{name} توسعه داد:\n{message}\n#{repo}"
    ),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    'webhook_endpoint' => env('TELEGRAM_WEBHOOK_ENDPOINT'),
];
