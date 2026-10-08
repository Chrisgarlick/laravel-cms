<?php

declare(strict_types=1);

namespace Cg\Cms\Lint\Rules;

use Cg\Cms\Lint\Excerpt;
use Cg\Cms\Lint\LintIssue;
use Cg\Cms\Lint\LintRule;

/**
 * Consultancy filler, flagged with counts.
 *
 * A warning, not a block. Each of these words has a legitimate use ("a robust
 * test suite" is fine, "robust end-to-end solutions" is not) and no rule can
 * tell them apart. What a count does is make the pattern visible: one
 * "leverage" is a word choice, six of them is a voice problem.
 *
 * The list comes from config, so tuning it never means touching the package.
 */
final class NoFillerWords implements LintRule
{
    /** @param array<int, string> $words */
    public function __construct(private readonly array $words) {}

    public function handle(): string
    {
        return 'no-filler-words';
    }

    public function check(string $text, string $field): array
    {
        $issues = [];

        foreach ($this->words as $word) {
            // Word boundaries so "leverage" does not match inside a longer
            // word, and a hyphen-tolerant pattern so "end-to-end" works.
            $pattern = '/(?<![\p{L}-])'.preg_quote($word, '/').'(?![\p{L}-])/iu';

            $count = preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);

            if ($count === 0 || $count === false) {
                continue;
            }

            [$matched, $offset] = $matches[0][0];

            $issues[] = new LintIssue(
                rule: $this->handle(),
                field: $field,
                message: $count === 1
                    ? "Filler word: \"{$word}\"."
                    : "Filler word: \"{$word}\", used {$count} times.",
                excerpt: Excerpt::around($text, $offset, strlen($matched)),
                suggestion: 'name the specific thing instead.',
                severity: LintIssue::WARN,
            );
        }

        return $issues;
    }
}
