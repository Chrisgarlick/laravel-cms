<?php

declare(strict_types=1);

namespace Cg\Cms\Lint\Rules;

use Cg\Cms\Lint\Excerpt;
use Cg\Cms\Lint\LintIssue;
use Cg\Cms\Lint\LintRule;

/**
 * British spelling, flagged as a warning.
 *
 * Whole words, matched against an explicit map. Two alternatives were tried and
 * rejected. A general -ize pattern calls "size", "prize" and "seize" American. A
 * stem plus suffix pattern gets the suggestion wrong on irregular forms: the
 * British form of "centered" is "centred", not "centreed". Listing the
 * inflections costs a few lines of config and is right every time.
 *
 * Two words are deliberately absent from the default map. "Program" is British
 * English for software (a "programme" is a broadcast or a schedule), so flagging
 * it on a site about building software would be wrong on nearly every article.
 * "Licence" and "license" differ by part of speech rather than dialect. Both are
 * one line in config for anyone who disagrees.
 */
final class UkSpelling implements LintRule
{
    /** @param array<string, string> $spellings US => UK, whole words */
    public function __construct(private readonly array $spellings) {}

    public function handle(): string
    {
        return 'uk-spelling';
    }

    public function check(string $text, string $field): array
    {
        $issues = [];

        foreach ($this->spellings as $us => $uk) {
            $pattern = '/(?<![\p{L}])'.preg_quote((string) $us, '/').'(?![\p{L}])/iu';

            if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            [$matched, $offset] = $matches[0];

            $issues[] = new LintIssue(
                rule: $this->handle(),
                field: $field,
                message: "US spelling: \"{$matched}\".",
                excerpt: Excerpt::around($text, $offset, strlen($matched)),
                suggestion: '"'.$uk.'"',
                severity: LintIssue::WARN,
            );
        }

        return $issues;
    }
}
