<?php

namespace App\Http\Controllers;

use App\Services\PunchStatsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

class PunchStatsController extends Controller
{
    public function __invoke(string $token, PunchStatsService $statsService): View|Response
    {
        $row = $statsService->findValidToken($token);

        if ($row === null) {
            return response()->view('punch.expired', [], 403);
        }

        if ($row->used_at === null) {
            $row->used_at = now();
            $row->save();
        }

        return view('punch.stats', [
            'dashboard' => $statsService->dashboard(),
        ]);
    }
}
