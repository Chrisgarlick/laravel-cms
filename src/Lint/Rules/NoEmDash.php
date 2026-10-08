<?php

declare(strict_types=1);

namespace Cg\Cms\Lint\Rules;

use Cg\Cms\Lint\Excerpt;
use Cg\Cms\Lint\LintIssue;
use Cg\Cms\Lint\LintRule;

/**
 * No em-dashes. The single most reliable tell that nobody edited the draft.
 *
 * Owns three spellings of the same mistake: the character itself, the HTML
 * entity, and the double-hyphen substitute. The entity forms are caught here
 * rather than in NoHtmlEntities so an author gets one precise message with the
 * rewrite table, instead of two vague ones.
 *
 * The double-hyphen case needs care on a site about software. `--write` is a CLI
 * flag and `--ink` is a CSS custom property, and neither is a punctuation
 * mistake. Code is already stripped before a rule sees the text (see
 * TextExtractor), and the patterns below additionally require the hyphens to sit
 * where punctuation sits: between spaces, or between two letters.
 */
final class NoEmDash implements LintRule
{
    private const EM_DASH = "\u{2014}";

    private const SUGGESTION = 'a comma, brackets, or a full stop and a new sentence. For a range, the word "to".';

    public function handle(): string
    {
        return 'no-em-dash';
    }

    public function check(string $text, string $field): array
    {
        $issues = [];

        $patterns = [
            '/'.self::EM_DASH.'/u' => 'an em-dash',
            '/&mdash;/i' => 'the &mdash; entity',
            '/&#(?:8212|x2014);/i' => 'a numeric em-dash entity',

            // Spaced double hyphen, the typewriter em-dash.
            '/(?<=\S)[ \t]+--[ \t]+(?=\S)/' => 'a double hyphen used as an em-dash',

            // Closed-up double hyphen between words. A leading `--` is a flag,
            // so the lookbehind for a letter is what keeps this off `--write`.
            '/(?<=[\p{L}])--(?=[\p{L}])/u' => 'a double hyphen used as an em-dash',
        ];

        foreach ($patterns as $pattern => $description) {
            if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            [$matched, $offset] = $matches[0];

            $issues[] = new LintIssue(
                rule: $this->handle(),
                field: $field,
                message: ucfirst($description).' is not allowed.',
                excerpt: Excerpt::around($text, $offset, strlen($matched)),
                suggestion: self::SUGGESTION,
                severity: LintIssue::BLOCK,
            );
        }

        return $issues;
    }
}
