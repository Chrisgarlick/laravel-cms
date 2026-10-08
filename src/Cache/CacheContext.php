<?php

declare(strict_types=1);

namespace Cg\Cms\Cache;

use Cg\Cms\Models\Entry;

/**
 * Records what a render depended on.
 *
 * Bound per-request. Anything that loads content registers itself here, and the
 * page cache middleware persists the collected tags against the URL it wrote.
 * That mapping is what makes invalidation precise: publishing an article can
 * purge the homepage without purging the site, because the homepage's render
 * declared a dependency on `collection:article`.
 *
 * A render that registers nothing is not cached. Silently caching a page whose
 * dependencies are unknown is how stale content survives forever.
 */
final class CacheContext
{
    /** @var array<string, true> */
    private array $tags = [];

    private bool $cacheable = true;

    /**
     * Depend on one specific entry.
     *
     * Registers only the entry's identity tag, never its collection tag. A page
     * showing one article is not invalidated by a different article changing.
     * Pages that genuinely list a collection call registerCollection() instead,
     * and a page doing both (a detail page with a "related" strip) calls both.
     */
    public function registerEntry(Entry $entry): void
    {
        $this->tags[$entry->cacheTag()] = true;
    }

    public function registerCollection(string $handle): void
    {
        $this->tags["collection:{$handle}"] = true;
    }

    public function registerTag(string $tag): void
    {
        $this->tags[$tag] = true;
    }

    /** Mark this response as never cacheable, whatever else happened. */
    public function doNotCache(): void
    {
        $this->cacheable = false;
    }

    /**
     * Clear collected state at the start of a request.
     *
     * Called by the CachePage middleware rather than left to the container's
     * scoped-instance lifecycle. Under PHP-FPM a scoped binding happens to be
     * per-request anyway, but relying on that breaks under Octane and inside a
     * test that issues two requests through the same application instance,
     * where tags from the first render leak into the second and produce
     * wildly over-broad invalidation.
     */
    public function reset(): void
    {
        $this->tags = [];
        $this->cacheable = true;
    }

    public function isCacheable(): bool
    {
        return $this->cacheable && $this->tags !== [];
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        return array_keys($this->tags);
    }
}
