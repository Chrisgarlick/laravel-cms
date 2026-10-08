<?php

declare(strict_types=1);

namespace Cg\Cms\Jobs;

use Cg\Cms\Cdn\CdnPurger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tells the edge to drop the URLs the origin just dropped.
 *
 * Queued, because it is an HTTP call to somebody else's API and an entry save
 * must not wait on it. The on-disk purge has already happened synchronously by
 * the time this runs, so the ordering is correct even if the job is delayed:
 * the origin is authoritative and already correct, and the edge catches up.
 *
 * Two attempts, not the default. A purge that has failed twice has failed
 * because Cloudflare is down or the token is wrong, and neither gets better by
 * being retried twenty more times while the queue backs up.
 */
final class PurgeCdn implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 30;

    /** @param array<int, string> $paths */
    public function __construct(public readonly array $paths) {}

    public function handle(CdnPurger $purger): void
    {
        if ($this->paths === []) {
            return;
        }

        $purger->purge($this->paths);
    }
}
