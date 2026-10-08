<?php

declare(strict_types=1);

namespace Cg\Cms\Cache;

use Illuminate\Support\Facades\DB;

/**
 * Version stamps, standing in for cache tags.
 *
 * Laravel's tagged cache needs Redis or Memcached, and the target box is a 1GB
 * droplet where Redis is 70MB better spent on PHP workers and OS page cache.
 * So listing caches embed a version in their key:
 *
 *     cms:list:article:v7
 *
 * Saving any article bumps the stamp to 8, and every listing key for articles
 * is unreachable at once. No tag index, works on the file store, and stale
 * files are swept by `cms:prune-cache` on the scheduler.
 */
final class CollectionVersion
{
    /** @var array<string, int> */
    private array $memo = [];

    public function current(string $collection): int
    {
        if (isset($this->memo[$collection])) {
            return $this->memo[$collection];
        }

        $version = DB::table('collection_versions')
            ->where('collection', $collection)
            ->value('version');

        if ($version === null) {
            DB::table('collection_versions')->insertOrIgnore([
                'collection' => $collection,
                'version' => 1,
                'updated_at' => now(),
            ]);

            $version = 1;
        }

        return $this->memo[$collection] = (int) $version;
    }

    public function bump(string $collection): int
    {
        $next = $this->current($collection) + 1;

        DB::table('collection_versions')->updateOrInsert(
            ['collection' => $collection],
            ['version' => $next, 'updated_at' => now()],
        );

        return $this->memo[$collection] = $next;
    }

    /** A cache key that self-invalidates when the collection changes. */
    public function key(string $collection, string $suffix): string
    {
        return sprintf('cms:list:%s:v%d:%s', $collection, $this->current($collection), $suffix);
    }
}
