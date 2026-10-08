<?php

declare(strict_types=1);

namespace Cg\Cms\Cache;

use Cg\Cms\Jobs\PurgeCdn;
use Cg\Cms\Jobs\WarmPageCache;
use Cg\Cms\Models\Entry;
use Cg\Cms\Models\PageCacheEntry;
use Illuminate\Support\Facades\DB;

/**
 * Resolves what a content change invalidates, then invalidates exactly that.
 *
 * Publishing an article purges the article, the article index, the homepage
 * (which embeds a blog-preview block) and any page whose render declared a
 * dependency on the article collection. It does not purge the site. On the
 * current Astro stack the same publish triggers a full rebuild.
 */
final class CachePurger
{
    public function __construct(
        private readonly PageCache $cache,
        private readonly CollectionVersion $versions,
    ) {}

    /** @return array<int, string> The URLs purged. */
    public function purge(Entry $entry): array
    {
        $tags = $entry->cacheTags();

        // Bump the collection stamp first. Any listing cache keyed on the old
        // version becomes unreachable immediately, so there is no window where
        // a purged page could be re-warmed from stale query results.
        $this->versions->bump($entry->collection);

        $urls = $this->urlsForTags($tags);

        // The entry's own URL may not be in the cache yet, on first publish.
        $urls = array_values(array_unique([...$urls, $entry->url()]));

        $this->cache->forgetMany($urls);
        $this->purgeCdn($urls);

        if (config('cg-cms.page_cache.warm_after_purge')) {
            WarmPageCache::dispatch($urls);
        }

        return $urls;
    }

    /**
     * @param  array<int, string>  $tags
     * @return array<int, string>
     */
    public function urlsForTags(array $tags): array
    {
        if ($tags === []) {
            return [];
        }

        return PageCacheEntry::query()
            ->whereIn('id', function ($query) use ($tags): void {
                $query->select('page_cache_entry_id')
                    ->from('page_cache_tags')
                    ->whereIn('tag', $tags);
            })
            ->pluck('url')
            ->all();
    }

    public function flush(): void
    {
        $this->cache->flush();

        DB::table('collection_versions')->increment('version');
    }

    /**
     * Tell the edge what the origin just dropped.
     *
     * Queued rather than inline. The disk purge above has already happened, so
     * the origin is correct and authoritative by this point; the edge catching
     * up a second later costs nothing, whereas a save that blocks on somebody
     * else's API costs the editor every time that API is slow.
     *
     * @param  array<int, string>  $urls
     */
    private function purgeCdn(array $urls): void
    {
        if ($urls === [] || config('cg-cms.cdn.driver') === 'null') {
            return;
        }

        PurgeCdn::dispatch($urls);
    }
}
