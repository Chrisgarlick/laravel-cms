<?php

declare(strict_types=1);

namespace Cg\Cms\Redirects;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Last chance to turn a 404 into a redirect.
 *
 * Hooked to NotFoundHttpException rather than to a fallback route, and that
 * distinction is the whole point. A fallback route only fires when *no route
 * matched*, but the common case is the opposite: `/article/{slug}` matches
 * fine and the controller throws 404 because that particular slug is gone.
 * A fallback-only implementation never fires for renamed content, which is
 * exactly the case slug history exists to serve.
 *
 * Handling the exception covers both: unrouted URLs and routed-but-missing.
 */
final class NotFoundHandler
{
    /**
     * Set on requests the CMS makes to itself, which must not be counted.
     *
     * The SEO audit follows every internal link to find the broken ones. If
     * those probes were recorded, every broken link would also appear in the
     * 404 log with a hit count the audit invented, and every redirect it
     * crossed would gain hits nobody made. A request attribute rather than a
     * header, because an attribute can only be set in-process: a header
     * would let any visitor hide their requests from the log.
     */
    public const UNCOUNTED = 'cgcms.uncounted';

    public function __construct(private readonly RedirectResolver $resolver) {}

    public function handle(Request $request): ?RedirectResponse
    {
        $path = $request->getPathInfo();
        $counted = ! $request->attributes->get(self::UNCOUNTED, false);

        if ($redirect = $this->resolver->resolve($path)) {
            if ($counted) {
                $this->resolver->recordHit($redirect);
            }

            return new RedirectResponse($redirect->to, $redirect->status);
        }

        if ($counted) {
            $this->log($request, $path);
        }

        return null;
    }

    /**
     * Record the miss so it can become a redirect in one click.
     *
     * This is the feature that makes redirect management actually happen. A
     * 404 report nobody sees is a 404 nobody fixes.
     */
    private function log(Request $request, string $path): void
    {
        // Ignore the noise. Scanners probing for WordPress, PHP files and
        // dotfiles would otherwise bury the real misses.
        if (preg_match('#\.(php|asp|aspx|jsp|env|git|sql|bak)$|^/wp-|^/\.#i', $path) === 1) {
            return;
        }

        if (mb_strlen($path) > 255) {
            return;
        }

        $referrer = $request->headers->get('referer');
        $referrer = $referrer !== null ? mb_substr($referrer, 0, 255) : null;

        // Inserted at zero then incremented, so a first sighting reads as one
        // hit rather than two. insertOrIgnore keeps concurrent 404s for the
        // same path from colliding on the unique index.
        DB::table('not_found_log')->insertOrIgnore([
            'path' => $path,
            'hits' => 0,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        DB::table('not_found_log')->where('path', $path)->update([
            'hits' => DB::raw('hits + 1'),
            'last_seen_at' => now(),
            'last_referrer' => $referrer,
        ]);
    }
}
