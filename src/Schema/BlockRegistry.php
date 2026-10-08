<?php

declare(strict_types=1);

namespace Cg\Cms\Schema;

use InvalidArgumentException;

/**
 * Resolves block definitions by handle.
 *
 * A container singleton rather than a static on Block, matching
 * CollectionRegistry. The earlier version kept a mutable static array, which
 * had three problems: it leaked between requests under Octane, it made
 * PHPUnit flag every test that touched block rendering as mutating global
 * state, and it meant a test could not register a block without polluting
 * every test that ran after it.
 */
final class BlockRegistry
{
    /** @var array<string, Block> */
    private array $blocks = [];

    /** @param array<int, Block>|null $blocks Defaults to the built-ins. */
    public function __construct(?array $blocks = null)
    {
        foreach ($blocks ?? [...Block::builtIns(), ...Block::app()] as $block) {
            $this->register($block);
        }
    }

    /** Register a custom block, or override a built-in of the same handle. */
    public function register(Block $block): void
    {
        $this->blocks[$block->handle] = $block;
    }

    public function has(string $handle): bool
    {
        return isset($this->blocks[$handle]);
    }

    public function find(string $handle): ?Block
    {
        return $this->blocks[$handle] ?? null;
    }

    /** @throws InvalidArgumentException */
    public function get(string $handle): Block
    {
        return $this->find($handle)
            ?? throw new InvalidArgumentException("Unknown block type [{$handle}].");
    }

    /** @return array<int, Block> */
    public function all(): array
    {
        return array_values($this->blocks);
    }

    /** @return array<int, string> */
    public function handles(): array
    {
        return array_keys($this->blocks);
    }
}
