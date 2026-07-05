<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

$routePrefix = static function (): string {
    return trim((string) parse_url((string) config('app.url', ''), PHP_URL_PATH), '/');
};

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        using: function () use ($routePrefix): void {
            $prefix = $routePrefix();

            Route::middleware('api')
                ->prefix($prefix !== '' ? "{$prefix}/api" : 'api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->prefix($prefix)
                ->group(base_path('routes/web.php'));

            $healthPath = $prefix !== '' ? "{$prefix}/up" : 'up';

            Route::get($healthPath, function () {
                $exception = null;

                try {
                    Event::dispatch(new DiagnosingHealth);
                } catch (\Throwable $e) {
                    if (app()->hasDebugModeEnabled()) {
                        throw $e;
                    }

                    report($e);

                    $exception = $e->getMessage();
                }

                return response(View::file(__DIR__.'/../vendor/laravel/framework/src/Illuminate/Foundation/resources/health-up.blade.php', [
                    'exception' => $exception,
                ]), status: $exception ? 500 : 200);
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'clickup.webhook' => \App\Http\Middleware\VerifyClickUpWebhookSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
