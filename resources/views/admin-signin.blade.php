@php
    /** @var \Cg\Cms\Admin\AdminVite $vite */
    $vite = app(\Cg\Cms\Admin\AdminVite::class);
    $site = (string) config('cg-cms.site.name');
@endphp
<!DOCTYPE html>
<html lang="en-GB" data-density="compact">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">

    <title>Sign in | {{ $site }}</title>

    {{--
        The admin stylesheet only, never the bundle.

        This screen deliberately loads no JavaScript. It has to work when the
        admin bundle is broken, because otherwise a bad build locks you out of
        the admin you would use to fix it, and there is no reason to hand the
        whole SPA to someone who has not signed in yet. The theme follows the
        system for the same reason: the saved choice needs script to read.
    --}}
    {!! $vite->stylesheetTags() !!}
</head>
<body class="bg-canvas text-fg">
    <main class="grid min-h-dvh lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
        <section class="flex flex-col px-6 py-8 sm:px-12">
            <div class="flex items-center gap-2.5">
                <span class="brand-mark flex size-9 items-center justify-center rounded-[10px] font-display text-[16px] font-semibold text-on-accent">{{ mb_substr($site, 0, 1) }}</span>
                <span class="font-display text-[16px] font-semibold tracking-[-0.01em]">{{ $site }}</span>
            </div>

            <div class="mx-auto flex w-full max-w-[360px] flex-1 flex-col justify-center py-12">
                <h1 class="text-[34px] leading-[1.05]">Welcome back</h1>
                <p class="mt-1.5 text-[14px] text-fg-muted">Sign in to manage {{ $site }}.</p>

                @if (session('error'))
                    <div class="kit-alert mt-6 !grid-cols-1" data-tone="bad" role="alert"><p>{{ session('error') }}</p></div>
                @endif

                @error('email')
                    <div class="kit-alert mt-6 !grid-cols-1" data-tone="bad" role="alert"><p>{{ $message }}</p></div>
                @enderror

                <form method="POST" action="{{ route('cgcms.admin.signin.store') }}" class="mt-7 space-y-4">
                    @csrf

                    <div class="kit-field">
                        <label for="email">Email</label>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus
                               class="kit-input" placeholder="you@example.com">
                    </div>

                    <div class="kit-field">
                        <label for="password">Password</label>
                        <input id="password" type="password" name="password" autocomplete="current-password" required
                               class="kit-input">
                    </div>

                    <label class="kit-choice">
                        <input type="checkbox" name="remember" value="1" class="kit-checkbox">
                        Stay signed in on this device
                    </label>

                    <button type="submit" class="kit-button w-full" data-variant="primary" data-size="lg">
                        Sign in
                    </button>
                </form>
            </div>

            <p class="text-[12px] text-fg-subtle">Private admin. Every sign-in attempt is rate limited.</p>
        </section>

        {{-- Brand panel: decoration only, hidden on small screens. --}}
        <aside class="relative hidden overflow-hidden bg-[#0b0d10] lg:block" aria-hidden="true">
            <div class="absolute -top-40 -right-40 size-[640px] rounded-full bg-[#2dd4bf] opacity-30 blur-[140px]"></div>
            <div class="absolute -bottom-48 -left-24 size-[520px] rounded-full bg-[#4f46e5] opacity-30 blur-[120px]"></div>
            <div class="absolute inset-0 bg-[linear-gradient(rgb(255_255_255/0.04)_1px,transparent_1px),linear-gradient(90deg,rgb(255_255_255/0.04)_1px,transparent_1px)] bg-[size:48px_48px] [mask-image:radial-gradient(ellipse_at_center,black_30%,transparent_75%)]"></div>

            <div class="relative flex h-full flex-col justify-end p-14">
                <div class="mb-10 w-[min(420px,90%)] rounded-[14px] border border-white/10 bg-white/[0.04] p-5 shadow-2xl backdrop-blur-md">
                    <div class="mb-4 flex items-center gap-2">
                        <span class="size-2.5 rounded-full bg-white/20"></span><span class="size-2.5 rounded-full bg-white/20"></span><span class="size-2.5 rounded-full bg-white/20"></span>
                    </div>
                    <div class="space-y-2.5">
                        <div class="h-3 w-2/3 rounded bg-white/25"></div>
                        <div class="h-2.5 w-full rounded bg-white/10"></div>
                        <div class="h-2.5 w-5/6 rounded bg-white/10"></div>
                        <div class="h-2.5 w-4/6 rounded bg-white/10"></div>
                    </div>
                    <div class="mt-5 flex items-center justify-between">
                        <span class="inline-flex h-5 items-center gap-1.5 rounded-full bg-[#4ade80]/15 px-2 text-[11px] font-medium text-[#4ade80]"><span class="size-1.5 rounded-full bg-current"></span>Published</span>
                        <span class="h-7 w-20 rounded-[8px] bg-[#2dd4bf]"></span>
                    </div>
                </div>
                <p class="max-w-md font-display text-[32px] leading-[1.15] font-semibold tracking-[-0.02em] text-white">Write it, check it, publish it. Served from disk in milliseconds.</p>
                <p class="mt-3 text-[14px] text-white/50">{{ $site }} · content management</p>
            </div>
        </aside>
    </main>
</body>
</html>
