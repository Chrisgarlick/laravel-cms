{{--
    The stylesheets a block preview needs beyond the site's own: those listed
    in cg-cms.preview_styles (kit's kit.css, say), then whatever the blocks
    pushed onto the kit-styles stack while rendering (each kit block pushes
    its section's CSS). An application's cms/block-preview view includes this
    in its <head>: @include('cgcms::preview-styles').
--}}
@foreach ((array) config('cg-cms.preview_styles', []) as $href)
<link rel="stylesheet" href="{{ $href }}">
@endforeach
@stack('kit-styles')
