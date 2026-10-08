<?php

declare(strict_types=1);

namespace Cg\Cms\Seo;

use Cg\Cms\Models\Entry;
use Cg\Cms\Redirects\RedirectResolver;
use Cg\Cms\Schema\Collection as CollectionSchema;
use Cg\Cms\Schema\CollectionRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Writes the sitemap, robots.txt, llms.txt and the feed to disk.
 *
 * Files rather than routes, deliberately. These four are read by machines, they
 * change only when content changes, and serving them from PHP means every
 * crawler hit boots the framework to produce a document that was identical last
 * time. On disk, nginx answers them and the origin never knows.
 *
 * The one rule that matters more than any of the formatting below: a sitemap
 * must not list a URL that 404s. A collection can declare a route pattern in the
 * schema long before a controller exists for it, which is the state `tag` and
 * `tool` are in today, so every candidate URL is checked against the registered
 * route table before it is written. Collections that are skipped are reported by
 * the command rather than dropped silently.
 */
final class SeoFileWriter
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly CollectionRegistry $collections,
        private readonly array $config,
        private readonly string $publicPath,
    ) {}

    /**
     * Write everything.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return [
            'sitemap' => $this->sitemap(),
            'robots' => $this->robots(),
            'llms' => $this->llms(),
            'rss' => $this->rss(),
        ];
    }

    /**
     * Sitemap index plus one child per collection.
     *
     * An index even when there is one child. The alternative is a format that
     * changes shape as the site grows, and a crawler that has cached the old
     * shape.
     *
     * @return array{files: array<int, string>, urls: int, skipped: array<int, string>}
     */
    public function sitemap(): array
    {
        $domain = $this->domain();
        $children = [];
        $skipped = [];
        $total = 0;

        foreach ($this->collections->all() as $collection) {
            if (! $this->isRoutable($collection)) {
                if ($collection->routePattern() !== null) {
                    $skipped[] = $collection->handle;
                }

                continue;
            }

            $urls = $this->urlsFor($collection);

            if ($urls === []) {
                continue;
            }

            $total += count($urls);

            foreach (array_chunk($urls, $this->chunkSize()) as $index => $chunk) {
                $name = $index === 0
                    ? "sitemap-{$collection->handle}.xml"
                    : "sitemap-{$collection->handle}-{$index}.xml";

                $this->write($name, $this->urlSet($chunk));
                $children[] = $name;
            }
        }

        // Static entry points: the home page and every collection index.
        $statics = $this->staticUrls();

        if ($statics !== []) {
            $total += count($statics);
            $this->write('sitemap-pages.xml', $this->urlSet($statics));
            array_unshift($children, 'sitemap-pages.xml');
        }

        $this->write($this->file('sitemap'), $this->sitemapIndex($children, $domain));

        return ['files' => $children, 'urls' => $total, 'skipped' => $skipped];
    }

    /**
     * robots.txt, with the sitemap pointer.
     *
     * The disallow list mirrors the sitemap exclusions, because a path worth
     * keeping out of the sitemap is a path worth keeping out of the index.
     */
    public function robots(): string
    {
        $disallow = [];

        foreach ($this->excluded() as $pattern) {
            // robots.txt matches by prefix, so `admin` and `admin/*` are the
            // same rule. Deduplicating after stripping the glob keeps the file
            // readable instead of listing each exclusion twice.
            $disallow[] = '/'.rtrim(ltrim(str_replace('*', '', $pattern), '/'), '/');
        }

        $disallow = array_values(array_unique(array_filter($disallow, fn (string $p): bool => $p !== '/')));

        $lines = ['User-agent: *'];

        foreach ($disallow as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        if ($disallow === []) {
            $lines[] = 'Disallow:';
        }

        $lines[] = '';
        $lines[] = 'Sitemap: '.$this->domain().'/'.ltrim($this->file('sitemap'), '/');

        $contents = implode("\n", $lines)."\n";

        $this->write($this->file('robots'), $contents);

        return $contents;
    }

    /**
     * llms.txt: the canonical pages, with a line each.
     *
     * The convention from llmstxt.org. Cheap to generate from content that
     * already exists, and it is the only artefact here aimed at answer engines
     * rather than crawlers. Worth having for the AEO work in team/18-seo.
     */
    public function llms(): string
    {
        $domain = $this->domain();

        $lines = ['# '.$this->string('name', 'Site')];

        $description = $this->string('description');

        if ($description !== null) {
            $lines[] = '';
            $lines[] = '> '.$description;
        }

        foreach ($this->collections->all() as $collection) {
            if (! $this->isRoutable($collection)) {
                continue;
            }

            $entries = $this->publishedIn($collection);

            if ($entries === []) {
                continue;
            }

            $lines[] = '';
            $lines[] = '## '.$collection->labelText();
            $lines[] = '';

            foreach ($entries as $entry) {
                $summary = $this->summaryFor($entry, $collection);

                $lines[] = '- ['.$entry->title.']('.$domain.$entry->url().')'
                    .($summary !== null ? ': '.$summary : '');
            }
        }

        $contents = implode("\n", $lines)."\n";

        $this->write($this->file('llms'), $contents);

        return $contents;
    }

    /**
     * RSS for the writing.
     *
     * Emits the pre-rendered HTML in content:encoded rather than re-rendering
     * anything: the HTML in `entries.rendered` is the same HTML the page
     * serves, which is the point of rendering on save.
     */
    public function rss(): string
    {
        $handle = $this->string('rss_collection', 'article');

        if ($handle === null || ! $this->collections->has($handle)) {
            return '';
        }

        $collection = $this->collections->get($handle);

        if (! $this->isRoutable($collection)) {
            return '';
        }

        $domain = $this->domain();
        $limit = (int) ($this->config['rss_limit'] ?? 20);

        $entries = Entry::query()
            ->collection($handle)
            ->published()
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();

        $items = '';

        foreach ($entries as $entry) {
            $url = $domain.$entry->url();
            $body = $this->bodyHtmlFor($entry, $collection);

            $items .= "\n        <item>"
                ."\n            <title>".$this->xml($entry->title).'</title>'
                ."\n            <link>".$this->xml($url).'</link>'
                ."\n            <guid isPermaLink=\"true\">".$this->xml($url).'</guid>'
                .($entry->published_at !== null
                    ? "\n            <pubDate>".$entry->published_at->toRfc2822String().'</pubDate>'
                    : '')
                ."\n            <description>".$this->xml((string) $this->summaryFor($entry, $collection)).'</description>'
                .($body !== '' ? "\n            <content:encoded>".$this->cdata($body).'</content:encoded>' : '')
                ."\n        </item>";
        }

        $self = $domain.'/'.ltrim($this->file('rss'), '/');

        $contents = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/">'."\n"
            .'    <channel>'."\n"
            .'        <title>'.$this->xml($this->string('name', 'Site') ?? 'Site').'</title>'."\n"
            .'        <link>'.$this->xml($domain.'/').'</link>'."\n"
            .'        <description>'.$this->xml($this->string('description') ?? '').'</description>'."\n"
            .'        <language>'.$this->xml(str_replace('_', '-', strtolower((string) ($this->config['locale'] ?? 'en-gb')))).'</language>'."\n"
            .'        <atom:link href="'.$this->xml($self).'" rel="self" type="application/rss+xml"/>'
            .$items."\n"
            .'    </channel>'."\n"
            .'</rss>'."\n";

        $this->write($this->file('rss'), $contents);

        return $contents;
    }

    /* -----------------------------------------------------------------------
     | URL collection
     |----------------------------------------------------------------------- */

    /**
     * @return array<int, array{loc: string, lastmod: ?string}>
     */
    private function urlsFor(CollectionSchema $collection): array
    {
        $domain = $this->domain();
        $urls = [];

        foreach ($this->publishedIn($collection) as $entry) {
            if ($this->isExcluded($entry->url()) || $this->isNoindex($entry)) {
                continue;
            }

            $urls[] = [
                'loc' => $domain.$entry->url(),
                'lastmod' => $entry->updated_at?->toAtomString(),
            ];
        }

        return $urls;
    }

    /**
     * The home page and every collection index that has a route behind it.
     *
     * @return array<int, array{loc: string, lastmod: ?string}>
     */
    private function staticUrls(): array
    {
        $domain = $this->domain();
        $urls = [['loc' => $domain.'/', 'lastmod' => null]];

        foreach ($this->collections->all() as $collection) {
            $path = $collection->indexPath();

            if ($path === null || $this->isExcluded($path) || ! $this->routeExists($path)) {
                continue;
            }

            // The index changes whenever its newest entry does.
            $lastmod = Entry::query()
                ->collection($collection->handle)
                ->published()
                ->max('updated_at');

            $urls[] = [
                'loc' => $domain.$path,
                'lastmod' => $lastmod !== null ? Carbon::parse((string) $lastmod)->toAtomString() : null,
            ];
        }

        return $urls;
    }

    /** @return array<int, Entry> */
    private function publishedIn(CollectionSchema $collection): array
    {
        return Entry::query()
            ->collection($collection->handle)
            ->published()
            ->orderBy($collection->orderColumn(), $collection->orderDirection())
            ->get()
            ->all();
    }

    /* -----------------------------------------------------------------------
     | Routability
     |----------------------------------------------------------------------- */

    /**
     * Does this collection have a detail route somebody can actually reach?
     *
     * The schema is the source of truth for what content exists; the route
     * table is the source of truth for what is served. They diverge whenever a
     * collection is defined ahead of its templates, and a sitemap that trusts
     * the schema alone advertises pages that do not exist.
     */
    private function isRoutable(CollectionSchema $collection): bool
    {
        $pattern = $collection->routePattern();

        return $pattern !== null && $this->routeExists($pattern);
    }

    private function routeExists(string $pattern): bool
    {
        return ServedRoutes::serves($pattern);
    }

    /* -----------------------------------------------------------------------
     | Formatting
     |----------------------------------------------------------------------- */

    /** @param array<int, array{loc: string, lastmod: ?string}> $urls */
    private function urlSet(array $urls): string
    {
        $body = '';

        foreach ($urls as $url) {
            $body .= "\n    <url>\n        <loc>".$this->xml($url['loc']).'</loc>';

            if ($url['lastmod'] !== null) {
                $body .= "\n        <lastmod>".$this->xml($url['lastmod']).'</lastmod>';
            }

            $body .= "\n    </url>";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .$body."\n"
            .'</urlset>'."\n";
    }

    /** @param array<int, string> $children */
    private function sitemapIndex(array $children, string $domain): string
    {
        $body = '';

        foreach ($children as $child) {
            $body .= "\n    <sitemap>\n        <loc>".$this->xml($domain.'/'.$child).'</loc>'
                ."\n    </sitemap>";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .$body."\n"
            .'</sitemapindex>'."\n";
    }

    /**
     * The one-line summary, from the most specific source that has one.
     *
     * An explicit SEO description outranks the collection's summary field,
     * which outranks the conventional names. Same order as SeoResolver uses,
     * because a feed and a meta description disagreeing about what a page is
     * about helps nobody.
     */
    private function summaryFor(Entry $entry, CollectionSchema $collection): ?string
    {
        $candidates = [
            data_get($entry->seo, 'description'),
            ...array_map(
                fn (string $field): mixed => $entry->value($field),
                array_filter([$collection->seoDescriptionField(), 'excerpt', 'summary', 'description']),
            ),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
            }
        }

        return null;
    }

    private function bodyHtmlFor(Entry $entry, CollectionSchema $collection): string
    {
        foreach ($collection->richTextFields() as $field) {
            $html = $entry->html($field->name);

            if ($html !== '') {
                return $html;
            }
        }

        return '';
    }

    /* -----------------------------------------------------------------------
     | Plumbing
     |----------------------------------------------------------------------- */

    /**
     * Write a file under public/, atomically.
     *
     * Same temp-then-rename as the page cache, for the same reason: nginx may
     * be serving this file to a crawler at the moment it is rewritten, and half
     * a sitemap is worse than a stale one.
     */
    private function write(string $relativePath, string $contents): string
    {
        $path = rtrim($this->publicPath, '/').'/'.ltrim($relativePath, '/');
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0o755, recursive: true);
        }

        $temp = $path.'.'.Str::random(8).'.tmp';

        if (file_put_contents($temp, $contents, LOCK_EX) === false) {
            return $path;
        }

        rename($temp, $path);

        return $path;
    }

    private function isExcluded(string $path): bool
    {
        // A URL that redirects is not a page: listing it in a sitemap is an
        // error in Search Console. Not added to robots.txt, though, because
        // crawlers need to follow the redirect to find where the page went.
        $resolver = app(RedirectResolver::class);

        if ($resolver->forced('/'.trim($path, '/')) !== null || $resolver->resolve($path) !== null) {
            return true;
        }

        $path = ltrim($path, '/');

        foreach ($this->excluded() as $pattern) {
            if (Str::is(ltrim($pattern, '/'), $path)) {
                return true;
            }
        }

        return false;
    }

    private function isNoindex(Entry $entry): bool
    {
        return (bool) data_get($entry->seo, 'noindex', false);
    }

    /** @return array<int, string> */
    private function excluded(): array
    {
        return array_values(array_filter((array) ($this->config['exclude'] ?? [])));
    }

    private function chunkSize(): int
    {
        return max(1, (int) ($this->config['sitemap_chunk'] ?? 50000));
    }

    private function file(string $key): string
    {
        $files = (array) ($this->config['files'] ?? []);

        return (string) ($files[$key] ?? $key);
    }

    private function domain(): string
    {
        return rtrim((string) ($this->config['domain'] ?? ''), '/');
    }

    private function string(string $key, ?string $default = null): ?string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * CDATA that cannot be escaped from.
     *
     * Author HTML containing the literal sequence `]]>` would otherwise close
     * the section early and leave the rest of the article sitting in the XML as
     * markup. Splitting the sequence across two sections is the standard fix.
     */
    private function cdata(string $html): string
    {
        return '<![CDATA['.str_replace(']]>', ']]]]><![CDATA[>', $html).']]>';
    }
}
