<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        channels: __DIR__.'/../routes/channels.php',
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Register the billboard token guard alias
        $middleware->alias([
            'billboard.token' => \App\Http\Middleware\EnsureBillboardToken::class,
            'admin.token'  => \App\Http\Middleware\EnsureAdminToken::class,
        ]);

        // Deliberately NOT statefulApi(). Every route in routes/api.php
        // authenticates with a Sanctum token -- boards carry billboard:* token
        // abilities, the control center carries an admin token -- and nothing
        // authenticates by session.
        //
        // statefulApi() adds EnsureFrontendRequestsAreStateful, which decides a
        // request is "first-party" by matching its Referer/Origin against the
        // stateful domains and then hands it the session guard. A browser always
        // sends one of those headers, so once the player moved into this app and
        // became same-origin, every board request looked first-party: Sanctum
        // tried to treat the authenticated Billboard as a session user and blew
        // up on Billboard::getAuthIdentifier(), which it has no reason to
        // implement. GET /sync returned 500 for the player while the identical
        // request from curl -- no Referer, no Origin -- returned 200.
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Return JSON for all API exceptions
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->expectsJson() || $request->is('api/*')
        );
    })
    ->create();
