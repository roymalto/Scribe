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
        // Behind a reverse proxy or tunnel: trust its X-Forwarded-* headers so URLs are generated as https.
        $middleware->trustProxies(at: '*');
        // Plain routes (the invitation link) send guests to the panel's sign-in
        $middleware->redirectGuestsTo('/admin/login');
        // Tell the database who is signed in (row-level security and the audit trail)
        $middleware->web(append: [\App\Http\Middleware\SetDatabaseUser::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
