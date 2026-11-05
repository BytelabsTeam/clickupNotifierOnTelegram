<?php

use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/telegram', TelegramWebhookController::class)
    ->middleware('telegram.webhook');
