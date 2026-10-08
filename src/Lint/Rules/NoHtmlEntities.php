<?php

declare(strict_types=1);

namespace Cg\Cms\Lint\Rules;

use Cg\Cms\Lint\Excerpt;
use Cg\Cms\Lint\LintIssue;
use Cg\Cms\Lint\LintRule;

/**
 * No HTML entities in authored text.
 *
 * TipTap stores text literally, so an entity typed into the editor is five
 * characters of text rather than the symbol the author meant. The renderer
 * escapes exactly once now (Kritano issue 16 was a double-escape), which means
 * `&amp;` reaches the page as the literal string `&amp;`. Type the character.
 *
 * Matched against a fixed list rather than a general `&\w+;` pattern, so prose
 * like "R&D; the usual" is not reported as an entity. Em-dash entities belong to
 * NoEmDash, which gives a better message for them.
 */
final class NoHtmlEntities implements LintRule
{
    /** @var array<string, string> entity => the character to type instead */
    private const ENTITIES = [
        'amp' => '&',
        'lt' => '<',
        'gt' => '>',
        'quot' => '"',
        'apos' => "'",
        'rsquo' => "\u{2019}",
        'lsquo' => "\u{2018}",
        'ldquo' => "\u{201C}",
        'rdquo' => "\u{201D}",
        'ndash' => "\u{2013}",
        'hellip' => "\u{2026}",
        'nbsp' => 'a normal space',
        'pound' => "\u{00A3}",
        'euro' => "\u{20AC}",
        'copy' => "\u{00A9}",
        'reg' => "\u{00AE}",
        'trade' => "\u{2122}",
        'deg' => "\u{00B0}",
    ];

    public function handle(): string
    {
        return 'no-html-entities';
    }

    public function check(string $text, string $field): array
    {
        $issues = [];

        foreach (self::ENTITIES as $entity => $replacement) {
            $pattern = '/&'.$entity.';/i';

            if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            [$matched, $offset] = $matches[0];

            $issues[] = new LintIssue(
                rule: $this->handle(),
                field: $field,
                message: "The &{$entity}; entity renders as literal text.",
                excerpt: Excerpt::around($text, $offset, strlen($matched)),
                suggestion: 'type '.$replacement.' directly.',
                severity: LintIssue::BLOCK,
            );
        }

        return $issues;
    }
}
