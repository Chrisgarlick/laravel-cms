<?php

declare(strict_types=1);

namespace Cg\Cms\Cdn;

/**
 * The default. Does nothing, successfully.
 *
 * Not a failure and not a warning. Most environments this code runs in have no
 * CDN in front of them, and an origin that logs an error every time it purges
 * in development is an origin whose logs nobody reads.
 */
final class NullPurger implements CdnPurger
{
    public function purge(array $paths): int
    {
        return 0;
    }

    public function purgeEverything(): bool
    {
        return true;
    }
}
