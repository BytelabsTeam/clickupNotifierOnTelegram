<?php

namespace App\Http\Controllers;

use App\Services\TelegramBotHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramBotHandler $handler): JsonResponse
    {
        try {
            $handler->handle($request->json()->all());
        } catch (\Throwable $exception) {
            report($exception);
        }

        return response()->json(['ok' => true]);
    }
}
