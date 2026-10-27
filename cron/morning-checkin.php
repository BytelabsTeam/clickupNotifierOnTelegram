<?php

/**
 * Daily morning check-in entrypoint for cPanel cron (Fetch URL or php CLI).
 *
 * Fetch URL (09:00 Asia/Tehran):
 *   https://yourdomain.com/clickup_notif_on_telegram/cron/morning-checkin.php?token=CLICKUP_CRON_TOKEN
 *
 * CLI:
 *   php cron/morning-checkin.php
 *   php cron/morning-checkin.php --token=CLICKUP_CRON_TOKEN
 */

declare(strict_types=1);

use App\Services\NotifyMorningCheckIn;
use Illuminate\Contracts\Console\Kernel;

define('LARAVEL_START', microtime(true));

require __DIR__.'/../vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

header('Content-Type: application/json; charset=utf-8');

$token = config('clickup.cron_token');

if (! is_string($token) || $token === '') {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'CLICKUP_CRON_TOKEN is not configured.',
    ], JSON_UNESCAPED_UNICODE);

    exit(1);
}

$provided = '';

if (PHP_SAPI === 'cli') {
    foreach ($argv ?? [] as $arg) {
        if (str_starts_with((string) $arg, '--token=')) {
            $provided = substr((string) $arg, strlen('--token='));
            break;
        }
    }

    // CLI cron on trusted hosts may omit the token.
    if ($provided === '') {
        $provided = $token;
    }
} else {
    $provided = (string) ($_GET['token'] ?? '');
}

if (! hash_equals($token, $provided)) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid cron token.',
    ], JSON_UNESCAPED_UNICODE);

    exit(1);
}

try {
    $result = $app->make(NotifyMorningCheckIn::class)->notify();
} catch (Throwable $exception) {
    report($exception);

    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);

    exit(1);
}

echo json_encode([
    'status' => 'ok',
    'sent' => $result['sent'],
    'message_id' => $result['message_id'],
    'work_date' => $result['work_date'],
    'mentions' => $result['mentions'],
    'timezone' => $result['timezone'],
    'checked_at' => now()->toIso8601String(),
], JSON_UNESCAPED_UNICODE);

exit($result['sent'] ? 0 : 1);
