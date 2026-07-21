<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Filament owns the only login screen post-cutover; the bare `auth`
        // middleware on the print/* routes needs an explicit guest redirect
        // now that the Breeze `login` named route is gone.
        $middleware->redirectGuestsTo(fn () => route('filament.app.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
