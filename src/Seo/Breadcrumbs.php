<?php

declare(strict_types=1);

namespace Cg\Cms\Seo;

use Cg\Cms\Schema\CollectionRegistry;
use Illuminate\Support\Str;

/**
 * A breadcrumb trail derived from the URL, not from hand-maintained data.
 *
 * Every page gets one, which is the only way to be sure every page has one. The
 * alternative is a field on the entry that is right on the day it is filled in
 * and wrong after the first URL change.
 *
 * Segment labels come from the collection whose index route matches, so
 * `/article` reads as whatever the collection calls itself rather than as
 * "Article". Segments with no collection behind them fall back to headline case,
 * which is the correct answer for a path like `/work/case-study`.
 */
final class Breadcrumbs
{
    /** @param array<string, mixed> $site */
    public function __construct(
        private readonly CollectionRegistry $collections,
        private readonly array $site,
    ) {}

    /**
     * @return array<int, array{name: string, url: string}>
     */
    public function forPath(string $path, ?string $currentTitle = null): array
    {
        $domain = rtrim((string) ($this->site['domain'] ?? ''), '/');
        $path = '/'.trim(parse_url($path, PHP_URL_PATH) ?: '/', '/');

        $crumbs = [['name' => 'Home', 'url' => $domain.'/']];

        if ($path === '/') {
            return $crumbs;
        }

        $segments = explode('/', trim($path, '/'));
        $accumulated = '';
        $last = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            $accumulated .= '/'.$segment;

            $crumbs[] = [
                'name' => $index === $last && $currentTitle !== null
                    ? $currentTitle
                    : $this->label($accumulated, $segment),
                'url' => $domain.$accumulated,
            ];
        }

        return $crumbs;
    }

    /**
     * Structured data for a trail.
     *
     * @param  array<int, array{name: string, url: string}>  $crumbs
     * @return array<string, mixed>
     */
    public function toSchema(array $crumbs, string $id): array
    {
        return [
            '@type' => 'BreadcrumbList',
            '@id' => $id,
            'itemListElement' => array_map(
                fn (array $crumb, int $index): array => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $crumb['name'],
                    'item' => $crumb['url'],
                ],
                $crumbs,
                array_keys($crumbs),
            ),
        ];
    }

    private function label(string $path, string $segment): string
    {
        foreach ($this->collections->all() as $collection) {
            if ($collection->indexPath() === $path) {
                return $collection->labelText();
            }
        }

        return $this->fallbackLabel($segment);
    }

    private function fallbackLabel(string $segment): string
    {
        // A slug segment is the entry's own, and by the time we reach one
        // without a title to use we are better off with readable words than
        // with the raw slug.
        return Str::headline(str_replace('-', ' ', $segment));
    }
}
