<?php

declare(strict_types=1);

namespace Cg\Cms\Http\Middleware;

use Cg\Cms\Cache\CacheContext;
use Cg\Cms\Cache\PageCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes a rendered response to the on-disk page cache.
 *
 * Every condition below is a reason a response must not be cached. The
 * cookie check is the important one: a response carrying Set-Cookie is
 * per-visitor by definition, and writing one to a shared cache would serve
 * one visitor's session to everyone.
 *
 * Public pages are cookie-free by design (no session middleware on the public
 * stack), which is also what makes Cloudflare cache-everything safe. There is
 * a test asserting no public GET route ever emits Set-Cookie.
 */
final class CachePage
{
    public function __construct(
        private readonly PageCache $cache,
        private readonly CacheContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Start from a clean slate. See CacheContext::reset().
        $this->context->reset();

        $response = $next($request);

        if ($this->shouldCache($request, $response)) {
            $response->headers->set('X-CG-Cache', 'MISS');
        }

        return $response;
    }

    /**
     * Write the cache after the response has been sent.
     *
     * Deliberately in terminate() rather than handle(). Compressing the HTML
     * and recording the dependency rows costs several milliseconds, and the
     * visitor who triggered the cold render should not pay for work that only
     * benefits the next visitor. Under PHP-FPM, fastcgi_finish_request has
     * already flushed the response by the time this runs, so the cost is
     * entirely off the critical path.
     */
    public function terminate(Request $request, Response $response): void
    {
        if (! $this->shouldCache($request, $response)) {
            return;
        }

        $this->cache->put(
            $request->getPathInfo(),
            (string) $response->getContent(),
            $this->context->tags(),
        );
    }

    private function shouldCache(Request $request, Response $response): bool
    {
        if (! $this->cache->enabled()) {
            return false;
        }

        if (! $request->isMethod('GET')) {
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        // Query strings would need to be part of the cache key, and every
        // public route on this site is a clean path. Anything with a query
        // string is either a preview, a filter or a tracking parameter.
        if ($request->getQueryString() !== null) {
            return false;
        }

        if ($request->hasSession() && $request->session()->isStarted()) {
            return false;
        }

        if (count($response->headers->getCookies()) > 0) {
            return false;
        }

        if ($this->isExcludedPath($request)) {
            return false;
        }

        // No declared dependencies means we cannot invalidate it later.
        return $this->context->isCacheable();
    }

    private function isExcludedPath(Request $request): bool
    {
        $patterns = (array) config('cg-cms.page_cache.except', []);

        return $patterns !== [] && $request->is(...$patterns);
    }
}
