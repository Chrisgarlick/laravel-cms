<?php

declare(strict_types=1);

namespace Cg\Cms\Console;

use Cg\Cms\Cache\PageCache;
use Cg\Cms\Jobs\WarmPageCache;
use Cg\Cms\Models\PageCacheEntry;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Sweeps the page cache of files and rows that no longer agree.
 *
 * Two kinds of rubbish accumulate, and each is harmless on its own and
 * misleading together.
 *
 * **Orphan files.** HTML on disk with no dependency row behind it. Nothing will
 * ever purge these, because purging works by looking up URLs from tags, so they
 * are served to visitors indefinitely: a page deleted from the CMS keeps
 * answering. That is the failure this command exists for.
 *
 * **Orphan rows.** A dependency row whose file is gone, usually because a deploy
 * replaced the release directory. They cost a little query time and make the
 * cache look fuller than it is.
 *
 * Run on the scheduler. Nothing here is urgent, and a dry run first is the
 * sensible default when pointing it at production for the first time.
 */
final class PruneCacheCommand extends Command
{
    protected $signature = 'cms:prune-cache
        {--older-than= : Also evict pages written more than this many days ago (default: cg-cms.page_cache.max_age_days)}
        {--dry-run : Report what would be removed without removing it}';

    protected $description = 'Remove orphaned cached pages, and pages old enough to carry an expiring form token';

    public function handle(PageCache $cache): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $root = $cache->root();

        if (! is_dir($root)) {
            $this->components->info('No page cache directory. Nothing to prune.');

            return self::SUCCESS;
        }

        $known = PageCacheEntry::query()->pluck('path', 'url');
        $knownPaths = array_flip($known->values()->all());

        $orphanFiles = [];

        foreach ($this->htmlFiles($root) as $file) {
            if (! isset($knownPaths[$file])) {
                $orphanFiles[] = $file;
            }
        }

        $orphanRows = [];

        foreach ($known as $url => $path) {
            if (! is_file((string) $path)) {
                $orphanRows[] = (string) $url;
            }
        }

        /*
         * Pages older than the form-token window allows.
         *
         * A public form's token (FormToken) is valid for one to two 30-day
         * windows. A page that sits unchanged in the cache past that serves a
         * form whose every submission is rejected as forged, silently, which
         * is the worst way to lose a lead. Evicting at 14 days and re-warming
         * keeps every cached form well inside its token's life.
         */
        $maxAge = (int) ($this->option('older-than') ?? config('cg-cms.page_cache.max_age_days', 14));
        $expired = $maxAge > 0
            ? PageCacheEntry::query()->where('written_at', '<', now()->subDays($maxAge))->pluck('url')->all()
            : [];

        $this->components->twoColumnDetail('Cached files with no dependency row', (string) count($orphanFiles));
        $this->components->twoColumnDetail('Dependency rows with no file', (string) count($orphanRows));
        $this->components->twoColumnDetail("Pages older than {$maxAge} days", (string) count($expired));

        if ($dryRun) {
            foreach (array_slice($orphanFiles, 0, 20) as $file) {
                $this->components->twoColumnDetail(str_replace($root.'/', '', $file), '<fg=yellow>would delete</>');
            }

            if (count($orphanFiles) > 20) {
                $this->components->twoColumnDetail('...and more', (string) (count($orphanFiles) - 20));
            }

            return self::SUCCESS;
        }

        foreach ($orphanFiles as $file) {
            // Take the compressed siblings with it. Leaving a .gz behind means
            // nginx keeps serving the stale copy to anything sending
            // Accept-Encoding, which is everything.
            foreach ([$file, $file.'.gz', $file.'.br'] as $candidate) {
                if (is_file($candidate)) {
                    @unlink($candidate);
                }
            }
        }

        if ($orphanRows !== []) {
            PageCacheEntry::query()->whereIn('url', $orphanRows)->delete();
        }

        if ($expired !== []) {
            $cache->forgetMany($expired);

            // Re-rendered at once rather than on the next visit, so the page
            // stays a cache hit; queued, so the scheduler is not held up.
            if (config('cg-cms.page_cache.warm_after_purge', true)) {
                WarmPageCache::dispatch(array_values($expired));
            }
        }

        $this->components->info(sprintf(
            'Pruned %d file(s) and %d row(s); refreshed %d aged page(s).',
            count($orphanFiles),
            count($orphanRows),
            count($expired),
        ));

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function htmlFiles(string $root): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            // Only the canonical files are compared. The .gz and .br siblings
            // are never recorded as a path, so treating them as orphans would
            // delete the entire cache on the first run.
            if ($file->isFile() && $file->getExtension() === 'html') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
