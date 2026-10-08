<?php

declare(strict_types=1);

namespace Cg\Cms\Http\Middleware;

use Cg\Cms\Redirects\NotFoundHandler;
use Cg\Cms\Redirects\RedirectResolver;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirects flagged `force`, applied before routing.
 *
 * An ordinary redirect only runs when nothing else answers the URL, which is
 * right for old addresses. A forced one wins even when a route or a published
 * entry still serves that path: retiring a page that is still in the code, or
 * moving a URL before its old page is removed. Without this, ticking "force"
 * in the admin did nothing, because nothing read the flag.
 *
 * Exact matches only, from the resolver's cached map: one array lookup per
 * request. Goes before CachePage in the public group, so a redirected URL is
 * never written to the page cache.
 */
final class ForcedRedirects
{
    public function __construct(private readonly RedirectResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $next($request);
        }

        $redirect = $this->resolver->forced($request->getPathInfo());

        if ($redirect === null) {
            return $next($request);
        }

        if (! $request->attributes->get(NotFoundHandler::UNCOUNTED, false)) {
            $this->resolver->recordHit($redirect);
        }

        // 410 Gone has no target; everything else is a redirect.
        if ($redirect->status === 410) {
            abort(410);
        }

        return new RedirectResponse($redirect->to, $redirect->status);
    }
}
