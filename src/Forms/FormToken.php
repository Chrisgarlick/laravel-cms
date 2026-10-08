<?php

declare(strict_types=1);

namespace Cg\Cms\Forms;

use Illuminate\Support\Facades\Config;

/**
 * A form token that survives being baked into a cached page.
 *
 * Public pages are served from disk with no session, so Laravel's session CSRF
 * token cannot be used: there is no session to tie one to, and a page rendered
 * three weeks ago would carry a token for a session that never existed.
 *
 * Worth being honest about what this is for. For an unauthenticated form whose
 * only effect is sending an email, classic CSRF protection is close to
 * meaningless: there is no victim session to ride and no privileged action to
 * trigger. The real threats are spam bots and scripted posts straight to the
 * endpoint. So this token's job is narrow: prove the submission came from a
 * page we served, cheaply, without a session. The honeypot, time-to-submit
 * check and rate limit in FormGuard do the heavier lifting.
 *
 * Design: HMAC over the form slug plus a rotating time window, with the
 * previous window also accepted. No fixed expiry stamped into the token.
 *
 * That choice matters. An expiry-bearing token embedded at cache-write time
 * creates a trap: cache the page on day one with a seven-day token, serve it on
 * day eight, and every submission fails with no obvious cause. Accepting two
 * rotating windows means a cached page keeps working for between one and two
 * window lengths, and `cms:prune-cache` re-warms well inside that.
 */
final class FormToken
{
    /** Window length. A cached page stays valid for one to two of these. */
    private const WINDOW = 30 * 24 * 60 * 60;

    public function issue(string $formSlug): string
    {
        return $this->sign($formSlug, $this->window());
    }

    public function verify(string $formSlug, ?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $current = $this->sign($formSlug, $this->window());

        // The previous window is accepted so a page cached just before a
        // rotation does not break the moment the window ticks over.
        $previous = $this->sign($formSlug, $this->window() - 1);

        return hash_equals($current, $token) || hash_equals($previous, $token);
    }

    private function window(): int
    {
        return intdiv(time(), self::WINDOW);
    }

    private function sign(string $formSlug, int $window): string
    {
        return hash_hmac(
            'sha256',
            $formSlug.'|'.$window,
            (string) Config::get('app.key'),
        );
    }
}
