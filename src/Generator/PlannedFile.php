<?php

declare(strict_types=1);

namespace Cg\Cms\Generator;

/** One file a generator will create or change. */
final class PlannedFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $contents,
        public readonly string $description,
        public readonly bool $insert = false,
    ) {}

    public function exists(): bool
    {
        return is_file($this->path);
    }
}
