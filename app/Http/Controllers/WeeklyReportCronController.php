<?php

namespace App\Http\Controllers;

use App\Services\WeeklyReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WeeklyReportCronController extends Controller
{
    public function __invoke(Request $request, WeeklyReportService $report): JsonResponse
    {
        $token = config('app.cron_token');

        if (! is_string($token) || $token === '') {
            abort(500, 'CRON_TOKEN is not configured.');
        }

        if (! hash_equals($token, (string) $request->query('token', ''))) {
            abort(403, 'Invalid cron token.');
        }

        try {
            $result = $report->sendIfDue();
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
            'period' => $result['period'],
            'checked_at' => now()->toIso8601String(),
        ]);
    }
}
