<?php

namespace App\Http\Controllers;

use App\Services\NotifyMorningCheckIn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MorningCheckInCronController extends Controller
{
    public function __invoke(Request $request, NotifyMorningCheckIn $notifier): JsonResponse
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
            'message_id' => $result['message_id'],
            'work_date' => $result['work_date'],
            'mentions' => $result['mentions'],
            'timezone' => $result['timezone'],
            'checked_at' => now()->toIso8601String(),
        ]);
    }
}
