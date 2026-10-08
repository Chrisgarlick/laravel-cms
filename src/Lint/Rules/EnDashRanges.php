<?php

declare(strict_types=1);

namespace Cg\Cms\Lint\Rules;

use Cg\Cms\Lint\Excerpt;
use Cg\Cms\Lint\LintIssue;
use Cg\Cms\Lint\LintRule;

/**
 * Number ranges get the word "to", not an en-dash.
 *
 * "Most builds take 2 to 6 weeks" rather than "2-6 weeks". A warning rather than
 * a block, because an en-dash in a date range inside a quotation is defensible
 * and a rule that blocks it would just teach people to paste around the editor.
 *
 * The suggestion carries the actual rewrite, so accepting it is a copy and
 * paste rather than a puzzle.
 */
final class EnDashRanges implements LintRule
{
    private const EN_DASH = "\u{2013}";

    public function handle(): string
    {
        return 'en-dash-ranges';
    }

    public function check(string $text, string $field): array
    {
        $pattern = '/(\d+(?:[.,]\d+)?)\s*'.self::EN_DASH.'\s*(\d+(?:[.,]\d+)?)/u';

        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === 0) {
            return [];
        }

        $issues = [];

        foreach ($matches as $match) {
            [$matched, $offset] = $match[0];

            $issues[] = new LintIssue(
                rule: $this->handle(),
                field: $field,
                message: 'An en-dash number range reads as machine output.',
                excerpt: Excerpt::around($text, $offset, strlen($matched)),
                suggestion: '"'.$match[1][0].' to '.$match[2][0].'"',
                severity: LintIssue::WARN,
            );
        }

        return $issues;
    }
}
