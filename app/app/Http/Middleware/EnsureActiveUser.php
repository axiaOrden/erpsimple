<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Reject authentication for deactivated users (re-checked on every request). */
class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user !== null && ! $user->active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(403, 'Your account has been deactivated.');
            }

            return redirect()->route('login')->withErrors([
                'email' => 'Your account has been deactivated. Contact your administrator.',
            ]);
        }

        return $next($request);
    }
}
