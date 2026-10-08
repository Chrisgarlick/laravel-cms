<?php

declare(strict_types=1);

namespace Cg\Cms\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Re-renders purged URLs so the next visitor gets a cache hit.
 *
 * Requests go over HTTP rather than through the kernel in-process, because the
 * point is to exercise the real middleware stack and produce a real cached
 * file. Sequential on purpose: on a single vCPU, warming must never compete
 * with live traffic.
 */
final class WarmPageCache implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    /** @param array<int, string> $urls */
    public function __construct(private readonly array $urls) {}

    public function handle(): void
    {
        $base = rtrim((string) config('app.url'), '/');

        foreach ($this->urls as $url) {
            try {
                Http::withHeaders(['X-CG-Warm' => '1'])
                    ->timeout(20)
                    ->get($base.$url);
            } catch (\Throwable $e) {
                // A failed warm is a cache miss for the next visitor, not an
                // error worth retrying the whole batch over.
                Log::warning('Page cache warm failed', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
