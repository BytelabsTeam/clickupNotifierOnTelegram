<?php

namespace App\Http\Controllers;

use App\Services\WeeklyTeamDigestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WeeklyDigestCronController extends Controller
{
    public function __invoke(Request $request, WeeklyTeamDigestService $digest): JsonResponse
    {
        $token = config('clickup.cron_token');

        if (! is_string($token) || $token === '') {
            abort(500, 'CLICKUP_CRON_TOKEN is not configured.');
        }

        if (! hash_equals($token, (string) $request->query('token', ''))) {
            abort(403, 'Invalid cron token.');
        }

        try {
            $result = $digest->notify();
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
            'used_llm' => $result['used_llm'],
            'checked_at' => now()->toIso8601String(),
        ]);
    }
}
