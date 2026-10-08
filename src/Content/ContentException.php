<?php

declare(strict_types=1);

namespace Cg\Cms\Content;

use RuntimeException;

/**
 * Why a content operation was refused, with every problem listed by field,
 * so whoever asked (a person at a terminal, or Claude Code) can fix them all
 * in one pass rather than one per attempt.
 */
final class ContentException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $problems
     */
    public function __construct(string $message, public readonly array $problems = [])
    {
        parent::__construct($message);
    }
}
