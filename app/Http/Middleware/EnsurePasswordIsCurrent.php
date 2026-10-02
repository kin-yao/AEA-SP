<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsCurrent
{
    /**
     * Stands between a signed-in user and the rest of the app whenever
     * they're still on a password someone else chose for them (a new
     * account or a reset), until they replace it with one of their own.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return redirect('/change-password');
        }

        return $next($request);
    }
}
