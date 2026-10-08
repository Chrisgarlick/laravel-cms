<?php

declare(strict_types=1);

namespace Cg\Cms\Schema;

use InvalidArgumentException;

/**
 * Resolves collection definitions by handle.
 *
 * Bound as a singleton, so config is parsed once per request rather than on
 * every entry load. The app registers its own collections and blocks through
 * `register()`, which is the extension point that keeps this package free of
 * anything specific to one site.
 */
final class CollectionRegistry
{
    /** @var array<string, Collection> */
    private array $collections = [];

    /** @param array<int, Collection> $collections */
    public function __construct(array $collections = [])
    {
        foreach ($collections as $collection) {
            $this->register($collection);
        }
    }

    public function register(Collection $collection): void
    {
        $this->collections[$collection->handle] = $collection;
    }

    public function has(string $handle): bool
    {
        return isset($this->collections[$handle]);
    }

    public function get(string $handle): Collection
    {
        return $this->collections[$handle]
            ?? throw new InvalidArgumentException("Unknown collection [{$handle}].");
    }

    /** @return array<string, Collection> */
    public function all(): array
    {
        return $this->collections;
    }

    /** @return array<int, string> */
    public function handles(): array
    {
        return array_keys($this->collections);
    }

    /** Collections that expose a public detail route. */
    public function routable(): array
    {
        return array_filter(
            $this->collections,
            fn (Collection $c): bool => $c->routePattern() !== null,
        );
    }
}
