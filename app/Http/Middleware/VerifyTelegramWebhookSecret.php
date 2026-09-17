<?php

namespace App\Http\Middleware;

use App\Services\TelegramNotifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyTelegramWebhookSecret
{
    public function __construct(private readonly TelegramNotifier $telegram)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $secret = $this->telegram->webhookSecret();

        $provided = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (! is_string($provided) || $provided === '' || ! hash_equals($secret, $provided)) {
            abort(401, 'Invalid Telegram webhook secret.');
        }

        return $next($request);
    }
}
