<?php

declare(strict_types=1);

namespace Cg\Cms\Console;

use Cg\Cms\Models\Entry;
use Cg\Cms\Schema\CollectionRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Renders pages ahead of the first visitor.
 *
 * Run after every deploy. Concurrency defaults to 1 because the production box
 * has a single vCPU and warming must never compete with live traffic.
 */
final class WarmCacheCommand extends Command
{
    protected $signature = 'cms:warm
        {--from=collections : Where to source URLs. collections|sitemap}
        {--concurrency=1 : Parallel requests. Keep at 1 on a single vCPU}';

    protected $description = 'Pre-render pages into the on-disk page cache';

    public function handle(CollectionRegistry $registry): int
    {
        $urls = $this->option('from') === 'sitemap'
            ? $this->urlsFromSitemap()
            : $this->urlsFromCollections($registry);

        if ($urls === []) {
            $this->components->warn('No URLs to warm.');

            return self::SUCCESS;
        }

        $base = rtrim((string) config('app.url'), '/');
        $warmed = 0;
        $redirected = 0;
        $failures = [];

        $this->components->info(sprintf('Warming %d URLs.', count($urls)));

        foreach ($urls as $url) {
            try {
                $response = Http::withHeaders(['X-CG-Warm' => '1'])
                    ->withoutRedirecting()
                    ->timeout(30)
                    ->get($base.$url);

                match (true) {
                    $response->successful() => $warmed++,
                    // A redirect is a legitimate response, just not a cacheable
                    // one: CachePage only writes 200s. Warming '/' hits this.
                    $response->redirect() => $redirected++,
                    default => $failures[$url] = (string) $response->status(),
                };
            } catch (\Throwable $e) {
                $failures[$url] = class_basename($e).': '.$e->getMessage();
            }
        }

        $this->components->twoColumnDetail('Warmed', (string) $warmed);

        if ($redirected > 0) {
            $this->components->twoColumnDetail('Redirects (not cached)', (string) $redirected);
        }

        if ($failures === []) {
            return self::SUCCESS;
        }

        // Name every failure. A bare count tells you something is wrong without
        // telling you what, which is the least useful possible output.
        $this->newLine();
        $this->components->error(sprintf('%d URL(s) failed to warm:', count($failures)));

        foreach ($failures as $url => $reason) {
            $this->components->twoColumnDetail($url, "<fg=red>{$reason}</>");
        }

        return self::FAILURE;
    }

    /** @return array<int, string> */
    private function urlsFromCollections(CollectionRegistry $registry): array
    {
        $urls = [];

        foreach ($registry->routable() as $handle => $collection) {
            if ($collection->indexPath() !== null) {
                $urls[] = $collection->indexPath();
            }

            Entry::query()
                ->collection($handle)
                ->published()
                ->orderBy($collection->orderColumn(), $collection->orderDirection())
                ->each(function (Entry $entry) use (&$urls): void {
                    $urls[] = $entry->url();
                });
        }

        return array_values(array_unique($urls));
    }

    /**
     * URLs from the sitemap on disk.
     *
     * sitemap.xml is an index, not a flat url set, so this follows it to each
     * child before reading anything. It still accepts a flat file: a sitemap
     * small enough to need no index is a legitimate shape and the difference is
     * one element name.
     *
     * @return array<int, string>
     */
    private function urlsFromSitemap(): array
    {
        $path = public_path((string) config('cg-cms.seo.files.sitemap', 'sitemap.xml'));

        if (! is_file($path)) {
            $this->components->warn('No sitemap on disk. Run cms:seo-files first.');

            return [];
        }

        $urls = [];

        foreach ($this->sitemapFiles($path) as $file) {
            $xml = @simplexml_load_file($file);

            if ($xml === false) {
                $this->components->warn('Could not parse '.basename($file).'.');

                continue;
            }

            foreach ($xml->url as $node) {
                $location = parse_url((string) $node->loc, PHP_URL_PATH);

                if (is_string($location)) {
                    $urls[] = $location;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * The index and everything it points at, or just the file if it is flat.
     *
     * @return array<int, string>
     */
    private function sitemapFiles(string $indexPath): array
    {
        $xml = @simplexml_load_file($indexPath);

        if ($xml === false) {
            return [];
        }

        if ($xml->getName() !== 'sitemapindex') {
            return [$indexPath];
        }

        $files = [];

        foreach ($xml->sitemap as $node) {
            $child = parse_url((string) $node->loc, PHP_URL_PATH);

            if (! is_string($child)) {
                continue;
            }

            $path = public_path(ltrim($child, '/'));

            if (is_file($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }
}
