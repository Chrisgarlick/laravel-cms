<?php

declare(strict_types=1);

namespace Cg\Cms\Cache;

use Cg\Cms\Models\PageCacheEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads and writes the on-disk HTML cache.
 *
 * Files land under public/<path>/ so nginx can serve them with try_files and
 * never start PHP. In production this is where roughly all traffic is answered.
 */
final class PageCache
{
    public function __construct(
        private readonly string $basePath,
        private readonly bool $enabled,
        /** @var array<int, string> */
        private readonly array $compress = ['gzip'],
    ) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Write a rendered response to disk and record its dependencies.
     *
     * @param  array<int, string>  $tags
     */
    public function put(string $url, string $html, array $tags): void
    {
        if (! $this->enabled || $tags === []) {
            return;
        }

        // The cache is an optimisation. Nothing in here may ever fail the
        // response the visitor is waiting for, so the whole write is
        // best-effort. Without this, concurrent first-hits on the same cold URL
        // (a thundering herd after a purge or a deploy) surface as 500s.
        try {
            $this->write($url, $html, $tags);
        } catch (Throwable $e) {
            Log::warning('Page cache write failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<int, string> $tags */
    private function write(string $url, string $html, array $tags): void
    {
        $file = $this->fileFor($url);

        $this->ensureDirectory(dirname($file));

        // Write to a unique temp file then rename. A half-written index.html
        // served to a visitor is worse than a cache miss, and rename(2) is
        // atomic on POSIX, so concurrent writers cannot interleave.
        $temp = $file.'.'.Str::random(8).'.tmp';

        if (file_put_contents($temp, $html, LOCK_EX) === false) {
            return;
        }

        rename($temp, $file);

        $this->writeCompressedSiblings($file, $html);
        $this->recordDependencies($url, $file, strlen($html), $tags);
    }

    /** Remove a single URL from the cache, files and dependency rows alike. */
    public function forget(string $url): void
    {
        $file = $this->fileFor($url);

        foreach ([$file, $file.'.gz', $file.'.br'] as $candidate) {
            if (is_file($candidate)) {
                @unlink($candidate);
            }
        }

        PageCacheEntry::query()->where('url', $url)->delete();
    }

    /** @return array<int, string> URLs that were purged. */
    public function forgetMany(array $urls): array
    {
        foreach ($urls as $url) {
            $this->forget($url);
        }

        return array_values($urls);
    }

    public function flush(): void
    {
        $root = $this->root();

        if (is_dir($root)) {
            $this->deleteDirectory($root);
        }

        PageCacheEntry::query()->delete();
    }

    public function has(string $url): bool
    {
        return is_file($this->fileFor($url));
    }

    public function root(): string
    {
        return rtrim($this->basePath, '/');
    }

    /**
     * Disk path for a URL.
     *
     * `/article/foo` becomes `<root>/article/foo/index.html`, which is the shape
     * nginx's `try_files $uri/index.html` expects. The root URL becomes
     * `<root>/index.html`.
     */
    public function fileFor(string $url): string
    {
        $path = trim(parse_url($url, PHP_URL_PATH) ?: '/', '/');

        return $path === ''
            ? $this->root().'/index.html'
            : $this->root().'/'.$path.'/index.html';
    }

    private function writeCompressedSiblings(string $file, string $html): void
    {
        if (in_array('gzip', $this->compress, true) && function_exists('gzencode')) {
            $encoded = gzencode($html, 9);

            if ($encoded !== false) {
                $temp = $file.'.gz.tmp';
                file_put_contents($temp, $encoded, LOCK_EX);
                rename($temp, $file.'.gz');
            }
        }

        // Brotli is preferred in production but ext-brotli is not always
        // present. nginx falls back to the gzip sibling when it is missing.
        if (in_array('brotli', $this->compress, true) && function_exists('brotli_compress')) {
            $encoded = brotli_compress($html, 11);

            if ($encoded !== false) {
                $temp = $file.'.br.tmp';
                file_put_contents($temp, $encoded, LOCK_EX);
                rename($temp, $file.'.br');
            }
        }
    }

    /**
     * Record which content this URL depended on.
     *
     * Race-tolerant by construction. Two concurrent first-hits on the same cold
     * URL will both try to record dependencies, so this uses upsert rather than
     * updateOrCreate (which does a select-then-insert and loses the race) and
     * insertOrIgnore for the tags.
     *
     * @param  array<int, string>  $tags
     */
    private function recordDependencies(string $url, string $file, int $bytes, array $tags): void
    {
        DB::transaction(function () use ($url, $file, $bytes, $tags): void {
            // ON CONFLICT (url) DO UPDATE. Atomic, so concurrent writers of the
            // same URL both succeed and the last one wins.
            PageCacheEntry::query()->upsert(
                [[
                    'url' => $url,
                    'path' => $file,
                    'bytes' => $bytes,
                    'written_at' => now(),
                ]],
                uniqueBy: ['url'],
                update: ['path', 'bytes', 'written_at'],
            );

            $id = PageCacheEntry::query()->where('url', $url)->value('id');

            if ($id === null) {
                return;
            }

            // Tags are additive and deduplicated by the unique index, so a
            // concurrent writer recording the same set is a no-op rather than a
            // constraint violation. Stale tags are cleared on purge, when the
            // row is deleted outright.
            DB::table('page_cache_tags')->insertOrIgnore(array_map(
                fn (string $tag): array => [
                    'page_cache_entry_id' => $id,
                    'tag' => $tag,
                ],
                $tags,
            ));
        });
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0o755, recursive: true);
        }
    }

    private function deleteDirectory(string $directory): void
    {
        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.'/'.$item;

            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
