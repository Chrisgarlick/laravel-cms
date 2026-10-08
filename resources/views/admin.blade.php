@php
    /** @var \Cg\Cms\Admin\AdminVite $vite */
    $vite = app(\Cg\Cms\Admin\AdminVite::class);
@endphp
<!DOCTYPE html>
<html lang="en-GB" data-cg-site="{{ config('cg-cms.site.name') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{--
        The admin is never indexed and never shared. It is behind auth, but a
        staging box with a guessable password and no robots directive is how
        an admin URL ends up in a search result.
    --}}
    <meta name="robots" content="noindex, nofollow">

    {{--
        Read by the two things that talk to the server outside Inertia:
        autosave and the live lint panel. Both are plain fetch calls, so they
        do not get the token Inertia attaches to its own requests, and the
        admin's CSRF middleware would reject them without it.
    --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{--
        The theme, applied before the stylesheet paints. Without this the page
        renders in the system theme and then flips when React mounts, which is
        the flash every dark-mode admin gets wrong. Mirrors hooks/useTheme.ts.
    --}}
    <script>try{var t=localStorage.getItem('cg-cms:theme');if(t==='light'||t==='dark')document.documentElement.dataset.theme=t}catch(e){}</script>

    <title inertia>{{ config('cg-cms.site.name') }}</title>

    {{--
        No Ziggy. The admin's URLs are the package's own, and the one thing the
        client needs to build them is the configured prefix, which arrives in
        shared props. Shipping the whole route table to the browser to avoid
        string concatenation is not a trade worth making.
    --}}
    {!! $vite->reactRefresh() !!}
    {!! $vite->tags() !!}
    @inertiaHead
</head>
<body>
    @unless ($vite->published())
        {{--
            The most likely first-deploy failure, named rather than left as a
            blank page with 404s in the console. The assets are committed to
            the package's dist/, so this is always one command away.
        --}}
        <noscript></noscript>
        <div style="padding:1rem 1.25rem;background:#b3261e;color:#fff;font:14px/1.5 system-ui">
            Admin assets have not been published. Run
            <code>php artisan vendor:publish --tag=cg-cms-assets --force</code>
        </div>
    @endunless

    @inertia
</body>
</html>
