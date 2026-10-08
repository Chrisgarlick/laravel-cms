<?php

declare(strict_types=1);

namespace Cg\Cms\Lint;

/**
 * A brand voice rule, encoded.
 *
 * Rules are pure functions of a string. They take plain text with code already
 * stripped out (see TextExtractor for why) and return what they object to. No
 * database, no config reads, no knowledge of entries: that keeps them unit
 * testable without booting Laravel, and it keeps the severity decision in one
 * place rather than scattered across the rule set.
 */
interface LintRule
{
    /** The handle used in config and in Field::lint(). */
    public function handle(): string;

    /**
     * @param  string  $text  Plain text, code already removed.
     * @param  string  $field  Field name, for reporting only.
     * @return array<int, LintIssue>
     */
    public function check(string $text, string $field): array;
}
