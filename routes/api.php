<?php

use App\Http\Controllers\ClickUpWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/clickup', ClickUpWebhookController::class)
    ->middleware('clickup.webhook');
