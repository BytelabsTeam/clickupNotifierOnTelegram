<?php

use App\Http\Controllers\DailyHighlightCronController;
use App\Http\Controllers\GitHubCommitsCronController;
use App\Http\Controllers\GitHubScoreCronController;
use App\Http\Controllers\MorningCheckInCronController;
use App\Http\Controllers\RegisterTelegramWebhookCronController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/cron/github-commits', GitHubCommitsCronController::class);
Route::get('/cron/weekly-report', \App\Http\Controllers\WeeklyReportCronController::class);
Route::get('/cron/github-score-commits', GitHubScoreCronController::class);
Route::get('/cron/morning-checkin', MorningCheckInCronController::class);
Route::get('/cron/daily-highlight', DailyHighlightCronController::class);
Route::get('/telegram/set-webhook', RegisterTelegramWebhookCronController::class);
Route::get('/cron/telegram-register-webhook', RegisterTelegramWebhookCronController::class);
