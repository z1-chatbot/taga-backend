<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Add CORS middleware globally
        $middleware->prepend(\App\Http\Middleware\CorsMiddleware::class);
        
        // The deployment endpoint is called by curl and by cron services that
        // cannot carry a CSRF token. It authenticates with MAINTENANCE_TOKEN
        // instead, which is not replayable from a browser session, so CSRF
        // protection is not what is guarding it.
        $middleware->validateCsrfTokens(except: [
            '__ops/*',
        ]);

        $middleware->alias([
            'auth.token' => \App\Http\Middleware\TokenAuthentication::class,
            // Populates the caller when a token is present, without rejecting
            // guests. For routes that legitimately serve both.
            'auth.optional' => \App\Http\Middleware\OptionalTokenAuthentication::class,
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'permission' => \App\Http\Middleware\PermissionMiddleware::class,
            'auth.agent' => \App\Http\Middleware\DeliveryAgentAuth::class,
            'auth.company' => \App\Http\Middleware\LogisticsCompanyAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
