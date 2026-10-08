<?php

declare(strict_types=1);

namespace Cg\Cms\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Sign in and out of the admin.
 *
 * Plain Blade rather than an Inertia page, on purpose. The sign-in screen is
 * the one thing that has to work before the SPA has loaded, and making it a
 * page component means a bad admin bundle locks you out of the admin that
 * would let you fix it. It is also the only screen an unauthenticated visitor
 * can reach, so keeping it off the JavaScript path keeps the bundle out of
 * reach of anyone who has not signed in.
 *
 * Section 5.11 has the rest: roles, and TOTP two-factor. This is the floor, not
 * the finished article, and the floor is what step 1 needs so that `/admin` is
 * never reachable without a session.
 */
final class SessionController
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function create(): View|RedirectResponse
    {
        if (Auth::guard($this->guard())->check()) {
            return redirect()->to($this->home());
        }

        return view('cgcms::admin-signin');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = $this->throttleKey($request);

        // Per email and IP rather than per IP alone. Throttling only by
        // address lets one attacker lock out a legitimate user from the same
        // network, and throttling only by email lets a botnet spread attempts
        // across addresses.
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '
                    .RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        if (! Auth::guard($this->guard())->attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            // One message for both a wrong password and an unknown address.
            // Distinguishing them tells an attacker which addresses exist.
            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($key);

        // Rotate the session ID on privilege change, so a session fixed before
        // sign-in is not the session that ends up authenticated.
        $request->session()->regenerate();

        return redirect()->intended($this->home());
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard($this->guard())->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(route('cgcms.admin.signin'));
    }

    private function throttleKey(Request $request): string
    {
        return 'cgcms-admin|'.Str::lower((string) $request->input('email')).'|'.$request->ip();
    }

    private function home(): string
    {
        return '/'.ltrim((string) config('cg-cms.admin.prefix', 'admin'), '/');
    }

    private function guard(): string
    {
        return (string) config('cg-cms.admin.guard', 'web');
    }
}
