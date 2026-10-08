<?php

declare(strict_types=1);

namespace Cg\Cms\View\Components;

use Cg\Cms\Models\Entry;
use Cg\Cms\Seo\JsonLd;
use Cg\Cms\Seo\SeoData;
use Cg\Cms\Seo\SeoResolver;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The entire head of a page, in one tag.
 *
 *     <x-cms-seo :entry="$article" />
 *     <x-cms-seo title="Writing" description="Notes on AI and building software" />
 *
 * One component rather than a partial per template, because the failure mode
 * being designed against is a page that forgets. Meta tags degrade silently: a
 * missing canonical or an absent description costs traffic for months before
 * anybody notices, and no test ever fails.
 *
 * Everything it emits is resolved by SeoResolver and JsonLd. This class only
 * decides which of the two entry points applies.
 */
final class Seo extends Component
{
    public function __construct(
        public ?Entry $entry = null,
        public ?string $title = null,
        public ?string $description = null,
        public ?string $path = null,
        public bool $noindex = false,

        /*
         * Structured data a template knows and the CMS does not: a Service
         * node with its audience, an FAQPage mirroring questions written into
         * a block, a CollectionPage listing. Appended to the same @graph, so
         * the page still emits one script element and the nodes can reference
         * the WebPage and Organization by @id. A `@context` on a node is
         * dropped, because the graph already has one.
         *
         * @var array<int, array<string, mixed>>
         */
        public array $jsonLd = [],

        // For listing pages, which have no entry to carry keywords.
        public ?string $keywords = null,

        // A social image for a page with no entry to resolve one from.
        public ?string $image = null,

        // Use `title` as written, without the site template.
        public bool $exactTitle = false,
    ) {}

    public function render(): View
    {
        $resolver = app(SeoResolver::class);
        $path = $this->path ?? request()->getPathInfo();

        $seo = $this->entry !== null
            ? $resolver->forEntry($this->entry, $path)
            : $resolver->forPage(
                title: $this->title ?? (string) config('cg-cms.site.name'),
                description: $this->description,
                path: $path,
                noindex: $this->noindex,
                exactTitle: $this->exactTitle,
            );

        // An explicit noindex on the tag wins over the entry's own setting, so
        // a template can suppress a page the content author has no view on.
        // An explicit image or keyword list fills a gap the resolver could not.
        $keywords = $this->keywords !== null
            ? array_values(array_filter(array_map('trim', explode(',', $this->keywords))))
            : $seo->keywords;

        if (($this->noindex && ! $seo->noindex) || ($this->image !== null && $seo->image === null) || $keywords !== $seo->keywords) {
            $seo = new SeoData(
                title: $seo->title,
                description: $seo->description,
                canonical: $seo->canonical,
                image: $seo->image ?? $this->absolute($this->image),
                imageAlt: $seo->imageAlt,
                type: $seo->type,
                noindex: $seo->noindex || $this->noindex,
                nofollow: $seo->nofollow,
                publishedAt: $seo->publishedAt,
                modifiedAt: $seo->modifiedAt,
                keywords: $keywords,
            );
        }

        $jsonLd = app(JsonLd::class);

        $graph = $jsonLd->graph(
            $seo,
            $this->entry,
            isHome: rtrim(parse_url($seo->canonical, PHP_URL_PATH) ?: '/', '/') === '',
        );

        foreach ($this->jsonLd as $node) {
            if (is_array($node) && $node !== []) {
                unset($node['@context']);
                $graph['@graph'][] = $node;
            }
        }

        return view('cgcms::seo', [
            'seo' => $seo,
            'siteName' => (string) config('cg-cms.site.name'),
            'locale' => (string) config('cg-cms.site.locale', 'en_GB'),
            'twitter' => config('cg-cms.seo.twitter'),
            'author' => (string) (config('cg-cms.seo.person.name') ?? config('cg-cms.site.name')),
            'graphJson' => $jsonLd->encode($graph),
        ]);
    }

    private function absolute(?string $url): ?string
    {
        if ($url === null || $url === '' || preg_match('#^https?://#', $url) === 1) {
            return $url ?: null;
        }

        return rtrim((string) config('cg-cms.site.domain'), '/').'/'.ltrim($url, '/');
    }
}
