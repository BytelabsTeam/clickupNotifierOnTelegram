<?php

namespace App\Http\Controllers;

use App\Services\PunchReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PunchRemindersCronController extends Controller
{
    public function __invoke(Request $request, PunchReminderService $service): JsonResponse
    {
        $token = config('clickup.cron_token');

        if (! is_string($token) || $token === '') {
            abort(500, 'CLICKUP_CRON_TOKEN is not configured.');
        }

        if (! hash_equals($token, (string) $request->query('token', ''))) {
            abort(403, 'Invalid cron token.');
        }

        try {
            $result = $service->run();
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 500);
        }

        return response()->json([
            'status' => 'ok',
            'reminded' => $result['reminded'],
            'auto_closed' => $result['auto_closed'],
            'checked_at' => $result['checked_at'],
        ]);
    }
}
