<?php

declare(strict_types=1);

namespace Cg\Cms\Cdn;

/**
 * Purges the edge cache.
 *
 * An interface with a null implementation, so the origin never has to know
 * whether a CDN is in front of it. Local development, CI and a box with no
 * Cloudflare account all resolve NullPurger and the calling code is identical.
 */
interface CdnPurger
{
    /**
     * Purge specific paths.
     *
     * Paths are site-relative (`/article/foo`). Turning them into whatever the
     * provider wants is the implementation's job.
     *
     * @param  array<int, string>  $paths
     * @return int Number of paths accepted for purging.
     */
    public function purge(array $paths): int;

    /** Purge everything. Deploys that change templates rather than content. */
    public function purgeEverything(): bool;
}
