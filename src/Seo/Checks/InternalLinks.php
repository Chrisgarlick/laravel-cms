<?php

declare(strict_types=1);

namespace Cg\Cms\Seo\Checks;

/**
 * Decides whether a link points at this site, and normalises it to a path.
 *
 * Both the absolute form (`https://chrisgarlick.com/work`) and the relative
 * form (`/work`) count, because content imported from the old CMS uses both,
 * and a link check that only understood one would report half the site's
 * internal links as missing.
 */
final class InternalLinks
{
    /** @var array<int, string> */
    private readonly array $hosts;

    /** @param array<int, string|null> $siteUrls The site domain and app.url, typically. */
    public function __construct(array $siteUrls)
    {
        $hosts = [];

        foreach ($siteUrls as $url) {
            $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower(preg_replace('/^www\./', '', $host) ?? $host);
            }
        }

        $this->hosts = array_values(array_unique($hosts));
    }

    /**
     * The path an internal href points at, or null for anything else.
     *
     * Query strings and fragments are dropped: `/work#clients` is a link to
     * `/work` as far as the link graph is concerned. A trailing slash is
     * dropped too, because nginx treats `/work/` and `/work` as one page and
     * the graph should agree.
     */
    public function path(string $href): ?string
    {
        $href = trim($href);

        if ($href === '' || str_starts_with($href, '#') || preg_match('/^(mailto|tel|javascript|data):/i', $href) === 1) {
            return null;
        }

        if (str_starts_with($href, '//')) {
            $href = 'https:'.$href;
        }

        $parts = parse_url($href);

        if ($parts === false) {
            return null;
        }

        if (isset($parts['host'])) {
            $host = strtolower(preg_replace('/^www\./', '', $parts['host']) ?? $parts['host']);

            if (! in_array($host, $this->hosts, true)) {
                return null;
            }
        } elseif (! str_starts_with($href, '/')) {
            // A relative link like `next-page` resolves against wherever the
            // reader is, which the CMS cannot know for content that appears on
            // several pages. Treated as not a link rather than guessed at.
            return null;
        }

        $path = '/'.ltrim((string) ($parts['path'] ?? '/'), '/');

        return $path === '/' ? $path : rtrim($path, '/');
    }

    /**
     * Internal paths among a list of hrefs, in order, duplicates kept.
     *
     * @param  array<int, string>  $hrefs
     * @return array<int, string>
     */
    public function paths(array $hrefs): array
    {
        return array_values(array_filter(array_map($this->path(...), $hrefs)));
    }
}
