<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MorningCheckInCronController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = config('clickup.cron_token');

        if (! is_string($token) || $token === '') {
            abort(500, 'CLICKUP_CRON_TOKEN is not configured.');
        }

        if (! hash_equals($token, (string) $request->query('token', ''))) {
            abort(403, 'Invalid cron token.');
        }

        // Morning broadcast disabled — users punch themselves via /punch.
        return response()->json([
            'status' => 'ok',
            'sent' => false,
            'disabled' => true,
            'message' => 'Morning check-in broadcast is disabled; use /punch instead.',
            'checked_at' => now()->toIso8601String(),
        ]);
    }
}
