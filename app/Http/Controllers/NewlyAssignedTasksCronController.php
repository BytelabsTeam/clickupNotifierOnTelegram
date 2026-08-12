<?php

namespace App\Http\Controllers;

use App\Services\NotifyNewlyAssignedTasks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NewlyAssignedTasksCronController extends Controller
{
    public function __invoke(Request $request, NotifyNewlyAssignedTasks $notifier): JsonResponse
    {
        $token = config('clickup.cron_token');

        if (! is_string($token) || $token === '') {
            abort(500, 'CLICKUP_CRON_TOKEN is not configured.');
        }

        if (! hash_equals($token, (string) $request->query('token', ''))) {
            abort(403, 'Invalid cron token.');
        }

        try {
            $result = $notifier->notify();
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 500);
        }

        return response()->json([
            'status' => 'ok',
            'sent' => $result['sent'],
            'notified' => $result['notified'],
            'checked_at' => now()->toIso8601String(),
        ]);
    }
}
