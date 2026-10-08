<?php

declare(strict_types=1);

namespace Cg\Cms\Forms;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Spam mitigation for public forms.
 *
 * Four cheap checks, in increasing cost order. None of them is individually
 * strong, and that is fine: the aim is to make a low-value target expensive
 * enough that automated submitters move on, without putting a CAPTCHA in front
 * of a genuine enquiry.
 *
 * Deliberately no third-party CAPTCHA. It would mean loading a script from
 * another origin on a page that is otherwise entirely self-contained, which
 * costs the CSP, the performance budget and the visitor's patience.
 */
final class FormGuard
{
    /** Field name a human never sees and a bot usually fills. */
    public const HONEYPOT = 'website_url';

    /** Hidden field holding when the form was rendered. */
    public const TIMESTAMP = 'rendered_at';

    /** Faster than this and it was not typed by a person. */
    private const MIN_SECONDS = 3;

    public function __construct(private readonly FormToken $token) {}

    /**
     * @return array<int, string> Reasons to reject. Empty means accept.
     */
    public function check(Request $request, string $formSlug): array
    {
        $reasons = [];

        if (! $this->token->verify($formSlug, $request->input('_form_token'))) {
            $reasons[] = 'token';
        }

        // A honeypot is a visually hidden field. Bots fill every input they
        // find; people cannot see it.
        if (filled($request->input(self::HONEYPOT))) {
            $reasons[] = 'honeypot';
        }

        $elapsed = $this->elapsed($request);

        if ($elapsed !== null && $elapsed < self::MIN_SECONDS) {
            $reasons[] = 'too-fast';
        }

        // Age is not a reason to reject. A page served from the cache can
        // legitimately be weeks old, every caller treats any reason as a
        // rejection, and so reporting 'stale' here silently dropped real
        // enquiries. Old pages are kept inside the token window instead, by
        // cms:prune-cache evicting and re-warming them daily.

        if (RateLimiter::tooManyAttempts($this->key($request, $formSlug), 5)) {
            $reasons[] = 'rate-limit';
        }

        return $reasons;
    }

    public function recordAttempt(Request $request, string $formSlug): void
    {
        // Per IP and form, decaying over an hour. Generous for a human, tight
        // for anything looping.
        RateLimiter::hit($this->key($request, $formSlug), 3600);
    }

    /** Hidden fields to render inside the form. */
    public function hiddenFields(string $formSlug): array
    {
        return [
            '_form_token' => $this->token->issue($formSlug),
            self::TIMESTAMP => (string) time(),
        ];
    }

    private function elapsed(Request $request): ?int
    {
        $rendered = $request->input(self::TIMESTAMP);

        if (! is_numeric($rendered)) {
            return null;
        }

        return time() - (int) $rendered;
    }

    private function key(Request $request, string $formSlug): string
    {
        return 'cgcms:form:'.$formSlug.':'.sha1((string) $request->ip());
    }
}
