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
        $middleware->alias([
            'password.current' => \App\Http\Middleware\EnsurePasswordIsCurrent::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // An expired page on a normal form post sends people back to sign in with a clear reason,
        // instead of a dead end. Livewire requests are handled in the browser (see form-feedback.js).
        $exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson() || $request->header('X-Livewire')) {
                return null;
            }

            return redirect()->guest(route('login'))->with('status', 'Your session expired. Please sign in again.');
        });

        // Deleting something that other records still depend on should explain itself, not crash.
        $exceptions->render(function (\Illuminate\Database\QueryException $e, \Illuminate\Http\Request $request) {
            $code = (string) ($e->errorInfo[1] ?? '');
            if (! in_array($code, ['1451', '1452', '1062'], true) || $request->expectsJson() || $request->header('X-Livewire')) {
                return null;
            }

            return back()->with('status', 'That could not be saved because it clashes with existing records.');
        });
    })->create();
