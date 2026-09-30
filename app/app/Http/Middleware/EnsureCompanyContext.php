<?php

namespace App\Http\Middleware;

use App\Models\CompanyMaster;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates the company switch parameter for SUPERADMINs and stores the
 * resolved company context in the session. Non-superadmins are fixed to
 * their own company — the switch parameter is ignored for them.
 */
class EnsureCompanyContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($user->isSuperadmin()) {
            $requested = $request->method() === 'GET'
                ? $request->query('company')
                : $request->input('company');

            if (is_string($requested) && $requested !== '') {
                $valid = CompanyMaster::where('company_id', $requested)->exists();

                if ($valid) {
                    session(['company_context' => $requested]);
                }
            }

            if (session('company_context') === null && $user->company_id !== null) {
                session(['company_context' => $user->company_id]);
            }
        } elseif (session()->has('company_context') && session('company_context') !== $user->company_id) {
            // Stale context from a previous superadmin session on shared session storage.
            session(['company_context' => $user->company_id]);
        }

        return $next($request);
    }
}
