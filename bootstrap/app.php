<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            // Keep the api/* default, but also honour an explicit JSON Accept header so
            // the auth gate (Task 3) returns 401 to the system-x fetch() endpoints rather
            // than a 302 -> /login redirect (which a JSON client can't follow).
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
