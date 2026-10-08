<?php

declare(strict_types=1);

namespace Cg\Cms\Seo\Checks;

use Cg\Cms\Lint\LintIssue;
use Illuminate\Support\Str;

/**
 * The editor-time SEO gates from section 5.7, as pure functions of a page.
 *
 * Findings are LintIssues, the same type the brand voice rules produce, so the
 * admin renders both with one component and the observer refuses both with
 * one exception. Severity is `block` for rules named in block_on_publish and
 * `warn` for everything else; whether a block actually blocks depends on
 * whether the entry is being published, which is the observer's decision,
 * not this class's.
 *
 * Pure in the same sense as the lint rules: no database, no config reads.
 * Checks that need other pages (duplicates, links in, orphans) live in the
 * callers that have them.
 */
final class SeoChecks
{
    /** @param array<string, mixed> $config cg-cms.seo.checks */
    public function __construct(private readonly array $config) {}

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    /** @return array<int, LintIssue> */
    public function check(PageFacts $page): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return array_map(
            $this->stamp(...),
            [
                ...$this->length('title-length', 'seo.title', 'Title', $page->title, 'title'),
                ...$this->length('description-length', 'seo.description', 'Description', $page->description, 'description'),
                ...$this->headings($page),
                ...$this->images($page),
                ...$this->links($page),
                ...$this->keyword($page),
            ],
        );
    }

    /** True for a rule that stops an entry being published. */
    public function blocksPublishing(string $rule): bool
    {
        return in_array($rule, (array) ($this->config['block_on_publish'] ?? []), true);
    }

    /**
     * Apply the configured severity to a finding from any SEO check.
     *
     * Public so the checks that need the database, which live elsewhere, are
     * held to the same block_on_publish list as the ones here.
     */
    public function stamp(LintIssue $issue): LintIssue
    {
        return $issue->withSeverity($this->blocksPublishing($issue->rule) ? LintIssue::BLOCK : LintIssue::WARN);
    }

    /** @return array<int, LintIssue> */
    private function length(string $rule, string $field, string $label, string $value, string $key): array
    {
        $min = (int) ($this->config[$key]['min'] ?? 0);
        $max = (int) ($this->config[$key]['max'] ?? PHP_INT_MAX);
        $length = mb_strlen($value);

        if ($length === 0) {
            return [new LintIssue($rule, $field, "{$label} is empty.")];
        }

        if ($length < $min) {
            return [new LintIssue(
                rule: $rule,
                field: $field,
                message: "{$label} is {$length} characters. Aim for {$min} to {$max}.",
                excerpt: $value,
                suggestion: 'say what the page is about in more specific words.',
            )];
        }

        if ($length > $max) {
            return [new LintIssue(
                rule: $rule,
                field: $field,
                message: "{$label} is {$length} characters and will be cut off around {$max}.",
                excerpt: Str::limit($value, $max + 20),
                suggestion: 'put the words that matter in the first '.$max.' characters.',
            )];
        }

        return [];
    }

    /** @return array<int, LintIssue> */
    private function headings(PageFacts $page): array
    {
        $issues = [];
        $h1s = $page->impliedH1 + count(array_filter($page->headings, fn (array $h): bool => $h['level'] === 1));

        if ($h1s === 0) {
            $issues[] = new LintIssue(
                rule: 'missing-h1',
                field: $page->contentField,
                message: 'The page has no h1.',
                suggestion: 'start with a heading that says what the page is.',
            );
        }

        if ($h1s > 1) {
            $issues[] = new LintIssue(
                rule: 'multiple-h1',
                field: $page->contentField,
                message: $page->impliedH1 > 0
                    ? 'The content contains an h1, but the page title is already the h1.'
                    : "The page has {$h1s} h1 headings.",
                excerpt: $this->firstHeading($page, 1),
                suggestion: $page->impliedH1 > 0 ? 'make it an h2.' : 'keep one h1 and make the rest h2.',
            );
        }

        // A skipped level is measured from the heading before it, starting
        // from the implied h1 where there is one, so an article body that
        // opens with an h3 is flagged and one that opens with an h2 is not.
        $previous = $page->impliedH1 > 0 ? 1 : 0;

        foreach ($page->headings as $heading) {
            if ($previous > 0 && $heading['level'] > $previous + 1) {
                $issues[] = new LintIssue(
                    rule: 'heading-order',
                    field: $page->contentField,
                    message: "An h{$heading['level']} follows an h{$previous}, skipping a level.",
                    excerpt: $heading['text'],
                    suggestion: 'make it an h'.($previous + 1).'.',
                );
            }

            $previous = $heading['level'];
        }

        return $issues;
    }

    /** @return array<int, LintIssue> */
    private function images(PageFacts $page): array
    {
        $missing = array_values(array_filter(
            $page->images,
            fn (array $image): bool => $image['alt'] === null || $image['alt'] === '',
        ));

        if ($missing === []) {
            return [];
        }

        return [new LintIssue(
            rule: 'image-alt',
            field: $page->contentField,
            message: count($missing) === 1
                ? 'An image has no alt text.'
                : count($missing).' images have no alt text.',
            excerpt: basename((string) parse_url($missing[0]['src'], PHP_URL_PATH)),
            suggestion: 'describe what the image shows, as you would to someone on the phone.',
        )];
    }

    /** @return array<int, LintIssue> */
    private function links(PageFacts $page): array
    {
        $minimum = (int) ($this->config['min_internal_links'] ?? 2);
        $count = count(array_unique($page->internalLinks));

        if ($count >= $minimum) {
            return [];
        }

        return [new LintIssue(
            rule: 'internal-links',
            field: $page->contentField,
            message: $count === 0
                ? 'The content links to no other page on the site.'
                : "The content links to {$count} other page".($count === 1 ? '' : 's').". Aim for at least {$minimum}.",
            suggestion: 'link to the article, service or case study a reader would want next.',
        )];
    }

    /**
     * The primary keyword in the title, the opening and an h2.
     *
     * Three findings rather than one, because each is fixed in a different
     * place and "keyword missing" without saying where sends somebody
     * searching the whole entry for it.
     *
     * @return array<int, LintIssue>
     */
    private function keyword(PageFacts $page): array
    {
        $keyword = trim((string) $page->keyword);

        if ($keyword === '') {
            return [];
        }

        $issues = [];

        if (! $this->mentions($page->title, $keyword)) {
            $issues[] = new LintIssue(
                rule: 'keyword-title',
                field: 'seo.title',
                message: "The title does not mention \"{$keyword}\".",
                excerpt: $page->title,
            );
        }

        $words = (int) ($this->config['opening_words'] ?? 100);
        $opening = implode(' ', array_slice(preg_split('/\s+/u', $page->text) ?: [], 0, $words));

        if (! $this->mentions($opening, $keyword)) {
            $issues[] = new LintIssue(
                rule: 'keyword-opening',
                field: $page->contentField,
                message: "\"{$keyword}\" does not appear in the first {$words} words.",
                excerpt: Str::limit($opening, 120),
            );
        }

        $h2s = array_filter($page->headings, fn (array $h): bool => $h['level'] === 2);

        if ($h2s !== [] && ! $this->mentions(implode(' ', array_column($h2s, 'text')), $keyword)) {
            $issues[] = new LintIssue(
                rule: 'keyword-heading',
                field: $page->contentField,
                message: "No h2 mentions \"{$keyword}\".",
            );
        }

        return $issues;
    }

    private function mentions(string $haystack, string $keyword): bool
    {
        return mb_stripos($haystack, $keyword) !== false;
    }

    private function firstHeading(PageFacts $page, int $level): string
    {
        foreach ($page->headings as $heading) {
            if ($heading['level'] === $level) {
                return $heading['text'];
            }
        }

        return '';
    }
}
