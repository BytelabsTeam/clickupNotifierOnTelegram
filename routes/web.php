<?php

use App\Http\Controllers\ClickUpPollCronController;
use App\Http\Controllers\GitHubCommitsCronController;
use App\Http\Controllers\GitHubScoreCronController;
use App\Http\Controllers\NewlyAssignedTasksCronController;
use App\Http\Controllers\RegisterTelegramWebhookCronController;
use App\Http\Controllers\TomorrowTasksCronController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/cron/clickup-poll', ClickUpPollCronController::class);
Route::get('/cron/github-commits', GitHubCommitsCronController::class);
Route::get('/cron/github-score-commits', GitHubScoreCronController::class);
Route::get('/cron/tomorrow-tasks', TomorrowTasksCronController::class);
Route::get('/cron/newly-assigned-tasks', NewlyAssignedTasksCronController::class);
Route::get('/telegram/set-webhook', RegisterTelegramWebhookCronController::class);
Route::get('/cron/telegram-register-webhook', RegisterTelegramWebhookCronController::class);
