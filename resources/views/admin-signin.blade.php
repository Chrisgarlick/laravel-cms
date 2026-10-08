@php
    /** @var \Cg\Cms\Admin\AdminVite $vite */
    $vite = app(\Cg\Cms\Admin\AdminVite::class);
    $site = (string) config('cg-cms.site.name');
@endphp
<!DOCTYPE html>
<html lang="en-GB">
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
                <span class="flex size-8 items-center justify-center rounded-[8px] bg-accent text-[14px] font-semibold text-on-accent shadow-sm">{{ mb_substr($site, 0, 1) }}</span>
                <span class="text-[14px] font-semibold">{{ $site }}</span>
            </div>

            <div class="mx-auto flex w-full max-w-[360px] flex-1 flex-col justify-center py-12">
                <h1 class="text-[26px] leading-tight font-semibold tracking-[-0.025em]">Welcome back</h1>
                <p class="mt-1.5 text-[14px] text-fg-muted">Sign in to manage {{ $site }}.</p>

                @if (session('error'))
                    <p class="mt-6 rounded-[var(--radius-control)] border border-danger/25 bg-danger-soft px-3 py-2 text-[13px] text-danger" role="alert">{{ session('error') }}</p>
                @endif

                @error('email')
                    <p class="mt-6 rounded-[var(--radius-control)] border border-danger/25 bg-danger-soft px-3 py-2 text-[13px] text-danger" role="alert">{{ $message }}</p>
                @enderror

                <form method="POST" action="{{ route('cgcms.admin.signin.store') }}" class="mt-7 space-y-4">
                    @csrf

                    <div>
                        <label for="email" class="mb-1.5 block text-[12.5px] font-medium">Email</label>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus
                               class="field-input !h-10 !text-[14px]" placeholder="you@example.com">
                    </div>

                    <div>
                        <label for="password" class="mb-1.5 block text-[12.5px] font-medium">Password</label>
                        <input id="password" type="password" name="password" autocomplete="current-password" required
                               class="field-input !h-10 !text-[14px]">
                    </div>

                    <label class="flex cursor-pointer items-center gap-2 text-[13px] text-fg-muted">
                        <input type="checkbox" name="remember" value="1" class="size-4 accent-[var(--accent)]">
                        Stay signed in on this device
                    </label>

                    <button type="submit" class="flex h-10 w-full items-center justify-center rounded-[var(--radius-control)] bg-accent text-[14px] font-medium text-on-accent shadow-sm transition-colors hover:bg-accent-hover focus-visible:ring-3 focus-visible:ring-[var(--accent-ring)] focus-visible:outline-none">
                        Sign in
                    </button>
                </form>
            </div>

            <p class="text-[12px] text-fg-subtle">Private admin. Every sign-in attempt is rate limited.</p>
        </section>

        {{-- Brand panel: decoration only, hidden on small screens. --}}
        <aside class="relative hidden overflow-hidden bg-[#0b0b0c] lg:block" aria-hidden="true">
            <div class="absolute -top-40 -right-40 size-[640px] rounded-full bg-[#ff5a1f] opacity-40 blur-[140px]"></div>
            <div class="absolute -bottom-48 -left-24 size-[520px] rounded-full bg-[#ff8a3d] opacity-20 blur-[120px]"></div>
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
                        <span class="inline-flex h-5 items-center gap-1.5 rounded-full bg-[#3fbf74]/15 px-2 text-[11px] font-medium text-[#3fbf74]"><span class="size-1.5 rounded-full bg-current"></span>Published</span>
                        <span class="h-7 w-20 rounded-md bg-[#ff5a1f]"></span>
                    </div>
                </div>
                <p class="max-w-md text-[28px] leading-[1.2] font-semibold tracking-[-0.025em] text-white">Write it, check it, publish it. Served from disk in milliseconds.</p>
                <p class="mt-3 text-[14px] text-white/50">{{ $site }} · content management</p>
            </div>
        </aside>
    </main>
</body>
</html>
