<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(function ($request) {
            // For API routes, always return null to prevent redirects
            if ($request->is('api/*') || 
                $request->expectsJson() || 
                $request->wantsJson() ||
                $request->header('Accept') === 'application/json' ||
                str_contains($request->header('Accept'), 'application/json')) {
                return null;
            }
            // For web routes, redirect to login
            return '/login';
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
