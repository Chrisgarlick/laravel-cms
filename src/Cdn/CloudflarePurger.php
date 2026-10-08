<?php

declare(strict_types=1);

namespace Cg\Cms\Cdn;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Purge by URL against the Cloudflare API.
 *
 * Three things this has to get right.
 *
 * **Absolute URLs.** Cloudflare purges by exact URL including scheme and host,
 * so a site-relative path purges nothing and reports success while doing it.
 *
 * **The batch limit.** purge_cache accepts 30 files per call on every plan below
 * Enterprise. A publish that invalidates 40 URLs is not unusual once a listing
 * and a few embedding pages are involved, so the call is chunked rather than
 * being allowed to fail the 31st URL silently.
 *
 * **Never throwing.** This runs from a queued job dispatched by an entry save.
 * The on-disk cache has already been purged correctly by the time it runs, so
 * the worst case if Cloudflare is unreachable is an edge serving stale HTML
 * until its TTL expires. That is a smaller problem than a save that fails, and
 * very much smaller than a retry storm against an API that is already down.
 */
final class CloudflarePurger implements CdnPurger
{
    /** Cloudflare's documented limit for purge_cache on non-Enterprise plans. */
    private const BATCH = 30;

    private const ENDPOINT = 'https://api.cloudflare.com/client/v4/zones/%s/purge_cache';

    public function __construct(
        private readonly string $zoneId,
        private readonly string $apiToken,
        private readonly string $domain,
        private readonly int $timeout = 10,
    ) {}

    public function purge(array $paths): int
    {
        $urls = $this->absolute($paths);

        if ($urls === []) {
            return 0;
        }

        $purged = 0;

        foreach (array_chunk($urls, self::BATCH) as $chunk) {
            if ($this->call(['files' => $chunk])) {
                $purged += count($chunk);
            }
        }

        return $purged;
    }

    public function purgeEverything(): bool
    {
        return $this->call(['purge_everything' => true]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function call(array $payload): bool
    {
        try {
            $response = Http::withToken($this->apiToken)
                ->timeout($this->timeout)
                ->acceptJson()
                ->post(sprintf(self::ENDPOINT, $this->zoneId), $payload);

            if ($response->successful() && $response->json('success') === true) {
                return true;
            }

            // Cloudflare returns 200 with success:false for several classes of
            // error, so the status alone is not the answer.
            Log::warning('Cloudflare purge rejected', [
                'status' => $response->status(),
                'errors' => $response->json('errors'),
            ]);

            return false;
        } catch (Throwable $e) {
            Log::warning('Cloudflare purge failed', [
                'error' => class_basename($e).': '.$e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private function absolute(array $paths): array
    {
        $domain = rtrim($this->domain, '/');

        $urls = [];

        foreach ($paths as $path) {
            if ($path === '') {
                continue;
            }

            $urls[] = str_starts_with($path, 'http')
                ? $path
                : $domain.'/'.ltrim($path, '/');
        }

        return array_values(array_unique($urls));
    }
}
