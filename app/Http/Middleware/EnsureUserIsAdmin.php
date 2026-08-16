<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-only routes.
 *
 * Deliberately answers 403 rather than redirecting to login for a signed-in
 * non-admin: they are authenticated, just not permitted, and bouncing them to a
 * login form they have already passed is confusing.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->guest(route('login'));
        }

        abort_unless($user->isAdmin(), 403, 'This area is for site administrators.');

        return $next($request);
    }
}
