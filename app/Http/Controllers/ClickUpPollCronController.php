<?php

namespace App\Http\Controllers;

use App\Services\PollClickUpDoneTasks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClickUpPollCronController extends Controller
{
    public function __invoke(Request $request, PollClickUpDoneTasks $poller): JsonResponse
    {
        $token = config('clickup.cron_token');

        if (! is_string($token) || $token === '') {
            abort(500, 'CLICKUP_CRON_TOKEN is not configured.');
        }

        if (! hash_equals($token, (string) $request->query('token', ''))) {
            abort(403, 'Invalid cron token.');
        }

        try {
            $count = $poller->poll();
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 500);
        }

        return response()->json([
            'status' => 'ok',
            'notified' => $count,
            'checked_at' => now()->toIso8601String(),
        ]);
    }
}
