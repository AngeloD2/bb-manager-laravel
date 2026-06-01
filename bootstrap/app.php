<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        channels: __DIR__.'/../routes/channels.php',
        api: __DIR__ . '/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Register the device token guard alias
        $middleware->alias([
            'device.token' => \App\Http\Middleware\EnsureDeviceToken::class,
            'admin.token'  => \App\Http\Middleware\EnsureAdminToken::class,
        ]);

        // Sanctum: tell it that Device is a tokenable model
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Return JSON for all API exceptions
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->expectsJson() || $request->is('api/*')
        );
    })
    ->create();
