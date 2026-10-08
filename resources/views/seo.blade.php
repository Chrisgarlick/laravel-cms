{{--
    Everything a page's head needs, emitted once.

    Order is deliberate: title and description first, because that is what a
    reader of the source looks for, then the canonical, then the social cards,
    then structured data.

    The JSON-LD is printed unescaped because it is already JSON, encoded with
    JSON_HEX_TAG so no author-supplied value can close the script element. See
    Cg\Cms\Seo\JsonLd::encode(). Everything else goes through {{ }}.
--}}
<title>{{ $seo->title }}</title>
<meta name="description" content="{{ $seo->description }}">
<link rel="canonical" href="{{ $seo->canonical }}">
{{--
    An indexable page still gets one directive: max-image-preview:large is not
    the default, and without it Google shows a thumbnail rather than the large
    image in Discover and image-heavy results.
--}}
<meta name="robots" content="{{ $seo->robots() ?? 'max-image-preview:large' }}">
@if (filled($author))
<meta name="author" content="{{ $author }}">
@endif
@if ($seo->keywords !== [])
<meta name="keywords" content="{{ implode(', ', $seo->keywords) }}">
@endif

<meta property="og:type" content="{{ $seo->type }}">
<meta property="og:title" content="{{ $seo->title }}">
<meta property="og:description" content="{{ $seo->description }}">
<meta property="og:url" content="{{ $seo->canonical }}">
<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:locale" content="{{ $locale }}">
@if ($seo->image !== null)
<meta property="og:image" content="{{ $seo->image }}">
@if ($seo->imageAlt !== null)
<meta property="og:image:alt" content="{{ $seo->imageAlt }}">
@endif
@endif
@if ($seo->type === 'article' && $seo->publishedAt !== null)
<meta property="article:published_time" content="{{ $seo->publishedAt }}">
@if ($seo->modifiedAt !== null)
<meta property="article:modified_time" content="{{ $seo->modifiedAt }}">
@endif
@endif

{{--
    summary_large_image without an image renders as a bare summary card, so the
    card type follows the image rather than being declared unconditionally.
--}}
<meta name="twitter:card" content="{{ $seo->image !== null ? 'summary_large_image' : 'summary' }}">
@if (filled($twitter))
<meta name="twitter:site" content="{{ $twitter }}">
<meta name="twitter:creator" content="{{ $twitter }}">
@endif

<script type="application/ld+json">{!! $graphJson !!}</script>
