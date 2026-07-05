<?php

use App\Http\Controllers\ClickUpPollCronController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/cron/clickup-poll', ClickUpPollCronController::class);
