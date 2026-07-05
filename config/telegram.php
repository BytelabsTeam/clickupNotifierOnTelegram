<?php

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'chat_id' => env('TELEGRAM_CHAT_ID'),
    'message_template' => env('TELEGRAM_MESSAGE_TEMPLATE', '{name} تسک "{task}" رو انجام داد ✅'),
];
