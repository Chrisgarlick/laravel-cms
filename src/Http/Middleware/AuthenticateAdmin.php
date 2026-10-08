<?php

declare(strict_types=1);

namespace Cg\Cms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the admin, without touching the host application's auth.
 *
 * Laravel's own `auth` middleware redirects to a route named `login`, which
 * means using it would make the package depend on the host application having
 * one, and would hijack that route's meaning if it exists for the public site.
 * A package-specific middleware that redirects to the package's own sign-in
 * screen keeps the two apps independent, which is the same principle the root
 * view and the Vite instance follow.
 *
 * The guard is configurable, so an application with its own admin guard can
 * point this at it rather than at `web`.
 */
final class AuthenticateAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = (string) config('cg-cms.admin.guard', 'web');

        if (Auth::guard($guard)->check()) {
            return $next($request);
        }

        // An Inertia request that gets a redirect to an HTML page would render
        // the sign-in markup inside the SPA. 409 with the location header is
        // the protocol's answer: the client performs a full page visit.
        if ($request->header('X-Inertia')) {
            return response('', 409)
                ->header('X-Inertia-Location', route('cgcms.admin.signin'));
        }

        return redirect()
            ->guest(route('cgcms.admin.signin'))
            ->with('error', 'Sign in to continue.');
    }
}
