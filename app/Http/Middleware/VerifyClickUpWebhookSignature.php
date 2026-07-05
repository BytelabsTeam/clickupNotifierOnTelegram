<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyClickUpWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('clickup.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            abort(500, 'CLICKUP_WEBHOOK_SECRET is not configured.');
        }

        $signature = $request->header('X-Signature');

        if (! is_string($signature) || $signature === '') {
            abort(401, 'Missing webhook signature.');
        }

        $expectedSignature = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expectedSignature, $signature)) {
            abort(401, 'Invalid webhook signature.');
        }

        return $next($request);
    }
}
