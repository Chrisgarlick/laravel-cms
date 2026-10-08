<?php

declare(strict_types=1);

namespace Cg\Cms\Seo;

use Cg\Cms\Models\Entry;
use Cg\Cms\Seo\Schema\SchemaBuilder;
use JsonException;

/**
 * Assembles one `@graph` per page and encodes it safely.
 *
 * A graph rather than a pile of separate script tags. Nodes carry stable `@id`
 * values and reference each other, so the organisation is described once and
 * every article points at it. Repeating the publisher block on every page is
 * both larger and weaker: crawlers treat repeated anonymous nodes as separate
 * entities.
 *
 * Encoding is the part that has to be right. JSON-LD sits inside a script
 * element, so a title containing `</script>` would otherwise close the element
 * and drop author-controlled markup into the document. JSON_HEX_TAG escapes the
 * angle brackets, which makes that impossible regardless of what anyone types
 * into the title field. There is a test that tries exactly this.
 */
final class JsonLd
{
    /**
     * @param  array<string, mixed>  $site  Merged site and SEO config.
     */
    public function __construct(
        private readonly array $site,
        private readonly Breadcrumbs $breadcrumbs,
    ) {}

    /**
     * The full graph for a page.
     *
     * @return array<string, mixed>
     */
    public function graph(SeoData $seo, ?Entry $entry = null, bool $isHome = false): array
    {
        $domain = rtrim((string) ($this->site['domain'] ?? ''), '/');

        $ids = [
            'organization' => $domain.'/#organization',
            'person' => $domain.'/#person',
            'website' => $domain.'/#website',
            'webpage' => $seo->canonical.'#webpage',
            'area_served' => $this->site['area_served'] ?? null,
            'currency' => $this->site['currency'] ?? null,
        ];

        $nodes = [
            $this->organization($ids, $domain),
            $this->person($ids, $domain),
            $this->website($ids, $domain, $isHome),
        ];

        $crumbs = $this->breadcrumbs->forPath($seo->canonical, $entry?->title);

        $nodes[] = $this->webPage($seo, $ids, $crumbs);
        $nodes[] = $this->breadcrumbs->toSchema($crumbs, $seo->canonical.'#breadcrumb');

        if ($seo->image !== null) {
            $nodes[] = $this->primaryImage($seo);
        }

        if ($entry !== null) {
            $nodes = [...$nodes, ...$this->collectionNodes($entry, $seo, $ids)];
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => array_values(array_filter($nodes)),
        ];
    }

    /**
     * Encode for embedding in a script element.
     *
     * @param  array<string, mixed>  $graph
     */
    public function encode(array $graph): string
    {
        try {
            return json_encode(
                $graph,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                    | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            // Invalid UTF-8 somewhere in the content. An empty graph costs a
            // rich result; a broken script tag costs the page.
            return '{}';
        }
    }

    /**
     * Nodes contributed by the entry's collection.
     *
     * @param  array<string, mixed>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function collectionNodes(Entry $entry, SeoData $seo, array $ids): array
    {
        $class = $entry->schema()->schemaOrgBuilder();

        if ($class === null || ! class_exists($class)) {
            return [];
        }

        $builder = app($class);

        if (! $builder instanceof SchemaBuilder) {
            return [];
        }

        return $builder->build($entry, $seo, $ids);
    }

    /**
     * @param  array<string, mixed>  $ids
     * @return array<string, mixed>
     */
    private function organization(array $ids, string $domain): array
    {
        $organization = (array) ($this->site['organization'] ?? []);

        return array_filter([
            '@type' => 'Organization',
            '@id' => $ids['organization'],
            'name' => $organization['name'] ?? $this->site['name'] ?? null,
            'url' => $domain.'/',
            'logo' => isset($organization['logo'])
                ? ['@type' => 'ImageObject', 'url' => $this->absolute((string) $organization['logo'])]
                : null,
            'email' => $this->site['contact_email'] ?? null,
            'sameAs' => $this->sameAs($organization['same_as'] ?? null),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param  array<string, mixed>  $ids
     * @return array<string, mixed>
     */
    private function person(array $ids, string $domain): array
    {
        $person = (array) ($this->site['person'] ?? []);

        return array_filter([
            '@type' => 'Person',
            '@id' => $ids['person'],
            'name' => $person['name'] ?? $this->site['name'] ?? null,
            'url' => $domain.'/',
            'jobTitle' => $person['job_title'] ?? null,
            'worksFor' => ['@id' => $ids['organization']],
            'sameAs' => $this->sameAs($person['same_as'] ?? null),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param  array<string, mixed>  $ids
     * @return array<string, mixed>
     */
    private function website(array $ids, string $domain, bool $isHome): array
    {
        $node = array_filter([
            '@type' => 'WebSite',
            '@id' => $ids['website'],
            'url' => $domain.'/',
            'name' => $this->site['name'] ?? null,
            'description' => $this->site['description'] ?? null,
            'publisher' => ['@id' => $ids['organization']],
            'inLanguage' => $this->site['locale'] ?? 'en_GB',
        ], fn (mixed $value): bool => $value !== null);

        // SearchAction only where there is a search to point at.
        //
        // The markup tells Google it may offer a sitelinks search box that
        // submits to this URL. Declaring one the site does not serve produces a
        // box that 404s, so this stays off until `seo.search_url` is set.
        $search = $this->site['search_url'] ?? null;

        if ($isHome && is_string($search) && $search !== '') {
            $node['potentialAction'] = [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $this->absolute($search),
                ],
                'query-input' => 'required name=search_term_string',
            ];
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $ids
     * @param  array<int, array{name: string, url: string}>  $crumbs
     * @return array<string, mixed>
     */
    private function webPage(SeoData $seo, array $ids, array $crumbs): array
    {
        return array_filter([
            '@type' => 'WebPage',
            '@id' => $ids['webpage'],
            'url' => $seo->canonical,
            'name' => $seo->title,
            'description' => $seo->description !== '' ? $seo->description : null,
            'isPartOf' => ['@id' => $ids['website']],
            'breadcrumb' => count($crumbs) > 1 ? ['@id' => $seo->canonical.'#breadcrumb'] : null,
            'primaryImageOfPage' => $seo->image !== null ? ['@id' => $seo->canonical.'#primaryimage'] : null,
            'datePublished' => $seo->publishedAt,
            'dateModified' => $seo->modifiedAt,
            'inLanguage' => $this->site['locale'] ?? 'en_GB',
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    private function primaryImage(SeoData $seo): array
    {
        return array_filter([
            '@type' => 'ImageObject',
            '@id' => $seo->canonical.'#primaryimage',
            'url' => $seo->image,
            'contentUrl' => $seo->image,
            'caption' => $seo->imageAlt,
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @return array<int, string>|null */
    private function sameAs(mixed $value): ?array
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        return array_values(array_filter(array_map('strval', $value)));
    }

    private function absolute(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim((string) ($this->site['domain'] ?? ''), '/').'/'.ltrim($url, '/');
    }
}
