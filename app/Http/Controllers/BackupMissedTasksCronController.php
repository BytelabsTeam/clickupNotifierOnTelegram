<?php

namespace App\Http\Controllers;

use App\Services\BackupMissedDoneTasks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BackupMissedTasksCronController extends Controller
{
    public function __invoke(Request $request, BackupMissedDoneTasks $backup): JsonResponse
    {
        $token = config('clickup.cron_token');

        if (! is_string($token) || $token === '') {
            abort(500, 'CLICKUP_CRON_TOKEN is not configured.');
        }

        if (! hash_equals($token, (string) $request->query('token', ''))) {
            abort(403, 'Invalid cron token.');
        }

        try {
            $result = $backup->run();
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 500);
        }

        return response()->json([
            'status' => 'ok',
            'day' => $result['day'],
            'fetched' => $result['fetched'],
            'queued' => $result['queued'],
            'registered' => $result['registered'],
            'notified' => $result['notified'],
            'remaining_days' => $result['remaining_days'],
            'remaining_tasks' => $result['remaining_tasks'],
            'clickup' => $result['clickup'],
            'github' => $result['github'],
            'checked_at' => now()->toIso8601String(),
        ]);
    }
}
