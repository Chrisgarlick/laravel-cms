<?php

declare(strict_types=1);

namespace Cg\Cms\Seo;

use Cg\Cms\Media\MediaResolver;
use Cg\Cms\Models\Entry;
use Closure;
use Illuminate\Support\Str;

/**
 * Resolves the head of a page, once, from the most specific source available.
 *
 * The chain runs: the entry's own SEO field, then the collection's template,
 * then the site default. Nothing further down is consulted once something
 * higher has answered, and nothing is left null, so a template never has to
 * decide what to do about a missing description.
 *
 * Construction takes plain arrays rather than reading config(), so the whole
 * class is testable without booting an application and so config caching is
 * never a question.
 */
final class SeoResolver
{
    /** Google truncates around 155 characters. Past that is wasted bytes. */
    private const DESCRIPTION_LIMIT = 155;

    /**
     * @param  array<string, mixed>  $site
     * @param  (Closure(): MediaResolver)|null  $media  Resolves library references. Optional so
     *                                                  the class stays testable without a database.
     */
    public function __construct(
        private readonly array $site,
        private readonly ?Closure $media = null,
    ) {}

    /** Meta for an entry's detail page. */
    public function forEntry(Entry $entry, ?string $path = null): SeoData
    {
        $seo = (array) $entry->seo;
        $schema = $entry->schema();

        $image = $this->resolveImage($entry, $seo);

        return new SeoData(
            title: $this->title(
                explicit: $this->string($seo, 'title'),
                // Null on a new entry previewed before its title is typed.
                subject: (string) $entry->title,
                template: $schema->seoTitleTemplate(),
            ),
            description: $this->description(
                explicit: $this->string($seo, 'description'),
                fallback: $this->descriptionFallback($entry),
            ),
            canonical: $this->canonical($this->string($seo, 'canonical') ?? $path ?? $entry->url()),
            image: $image['src'],
            imageAlt: $image['alt'],

            // OpenGraph's `article` type carries the dates that make a shared
            // link show when it was written. Anything without a published date
            // is a page rather than an article, whatever collection it is in.
            type: $entry->published_at !== null ? 'article' : 'website',
            noindex: (bool) ($seo['noindex'] ?? false),
            nofollow: (bool) ($seo['nofollow'] ?? false),
            publishedAt: $entry->published_at?->toIso8601String(),
            modifiedAt: $entry->updated_at?->toIso8601String(),
            keywords: $this->keywords($entry, $seo),
        );
    }

    /**
     * Meta for a page with no entry behind it: a listing, or the home page.
     */
    public function forPage(
        string $title,
        ?string $description = null,
        ?string $path = null,
        bool $noindex = false,
        bool $exactTitle = false,
    ): SeoData {
        return new SeoData(
            // Exact for a title written out in full, which the live site did
            // for most listings; the template would otherwise add the site
            // name a second time, or to a title deliberately written without.
            title: $this->title(explicit: $exactTitle ? $title : null, subject: $title, template: null),
            description: $this->description(explicit: $description, fallback: null),
            canonical: $this->canonical($path),
            image: $this->string($this->site, 'image'),
            imageAlt: $this->string($this->site, 'image_alt'),
            type: 'website',
            noindex: $noindex,
        );
    }

    /**
     * Apply the template that matches, or hand back the subject untouched.
     *
     * An entry whose SEO title is set has already made the decision, so no
     * template is applied to it: a hand-written title reading "Chris Garlick |
     * Chris Garlick" is the usual result of being clever here.
     */
    private function title(?string $explicit, string $subject, ?string $template): string
    {
        if ($explicit !== null) {
            return $explicit;
        }

        $template ??= $this->string($this->site, 'title_template') ?? '{title}';

        return trim(strtr($template, [
            '{title}' => $subject,
            '{site}' => (string) ($this->site['name'] ?? ''),
        ]));
    }

    private function description(?string $explicit, ?string $fallback): string
    {
        $description = $explicit
            ?? $fallback
            ?? $this->string($this->site, 'description')
            ?? '';

        return Str::limit(trim(preg_replace('/\s+/u', ' ', $description) ?? $description), self::DESCRIPTION_LIMIT, '');
    }

    /**
     * The collection's nominated summary field, if it named one.
     *
     * Falling back to the body would be worse than an empty description: the
     * first 155 characters of an article are almost never a summary of it, and
     * a bad description costs click-through on every result.
     */
    private function descriptionFallback(Entry $entry): ?string
    {
        $field = $entry->schema()->seoDescriptionField();

        if ($field === null) {
            return null;
        }

        $value = $entry->value($field);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * Absolute URL for the canonical tag.
     *
     * Always absolute and always on the configured domain. A relative canonical
     * is legal and a canonical pointing at the wrong host is the classic way to
     * deindex a staging copy's content along with the real site's.
     */
    private function canonical(?string $path): string
    {
        $path ??= '/';

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return rtrim((string) ($this->site['domain'] ?? ''), '/').'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $seo
     * @return array{src: ?string, alt: ?string}
     */
    private function resolveImage(Entry $entry, array $seo): array
    {
        // An explicit OG image wins, whatever shape it was stored in: a path
        // typed into the SEO panel, a legacy {src, alt}, or a library
        // reference.
        foreach (['og_image', 'image'] as $key) {
            $value = $seo[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return ['src' => $this->absolute($value), 'alt' => $this->string($seo, 'og_image_alt')];
            }

            $image = $this->fromMediaValue($value);

            if ($image !== null) {
                return $image;
            }
        }

        // Then the field the collection nominated, and only that field.
        //
        // Never "the first media field on the entry". `project` carries a cover
        // image for work that is under NDA, and a resolver that helped itself to
        // any media field would publish it in og:image on a page built to never
        // show it. A collection that nominates nothing gets the site default.
        $field = $entry->schema()->seoImageField();

        if ($field !== null) {
            $image = $this->fromMediaValue($entry->value($field));

            if ($image !== null) {
                return $image;
            }
        }

        return [
            'src' => $this->string($this->site, 'image') !== null
                ? $this->absolute((string) $this->site['image'])
                : null,
            'alt' => $this->string($this->site, 'image_alt'),
        ];
    }

    /**
     * A media field value as an og:image, or null if it does not hold one.
     *
     * Library images resolve to the `og` preset in the fallback format, never
     * AVIF or WebP: several of the crawlers that build link previews still
     * ignore both, and a preview with no image is the failure og:image exists
     * to prevent.
     *
     * @return array{src: string, alt: string|null}|null
     */
    private function fromMediaValue(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        if (MediaResolver::reference($value) !== null && $this->media !== null) {
            $image = ($this->media)()->resolve($value, 'og');

            return $image === null
                ? null
                : ['src' => $this->absolute($image->src), 'alt' => $image->alt !== '' ? $image->alt : null];
        }

        if (isset($value['src']) && is_string($value['src']) && $value['src'] !== '') {
            return ['src' => $this->absolute($value['src']), 'alt' => $this->string($value, 'alt')];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $seo
     * @return array<int, string>
     */
    private function keywords(Entry $entry, array $seo): array
    {
        $raw = $seo['keywords'] ?? $entry->value('keywords');

        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw)));
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    private function absolute(string $url): string
    {
        if (Str::startsWith($url, ['http://', 'https://', '//'])) {
            return $url;
        }

        return rtrim((string) ($this->site['domain'] ?? ''), '/').'/'.ltrim($url, '/');
    }

    /** @param array<string, mixed> $source */
    private function string(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
