<?php

use App\Http\Controllers\ClickUpWebhookController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/clickup', ClickUpWebhookController::class)
    ->middleware('clickup.webhook');

Route::post('/webhooks/telegram', TelegramWebhookController::class)
    ->middleware('telegram.webhook');
