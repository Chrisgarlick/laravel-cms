<?php

declare(strict_types=1);

namespace Cg\Cms\Lint;

use Cg\Cms\Lint\Rules\EnDashRanges;
use Cg\Cms\Lint\Rules\NoEmDash;
use Cg\Cms\Lint\Rules\NoFillerWords;
use Cg\Cms\Lint\Rules\NoHtmlEntities;
use Cg\Cms\Lint\Rules\UkSpelling;
use Cg\Cms\Models\Entry;
use Cg\Cms\Schema\Collection as CollectionSchema;
use Cg\Cms\Schema\Field;
use Closure;
use Throwable;

/**
 * Runs the brand voice rules over an entry.
 *
 * The rules in CLAUDE.md, encoded so they cannot be forgotten. Blocking rules
 * fail the save; warnings surface in the editor and save anyway.
 *
 * Which rules run, and at what severity, is decided here rather than inside the
 * rules. A rule declares a sensible default severity, config has the final say,
 * and a site that wants em-dashes back changes one array without touching any
 * rule. The rules stay pure functions of a string, which is what makes them
 * testable without a database.
 */
final class Linter
{
    /**
     * Fields holding prose. Everything else is structure.
     *
     * A slug has hyphens by design, a URL is not English, and a select value is
     * whatever the schema says it is. Linting them produces noise, and a linter
     * that produces noise gets switched off.
     *
     * @var array<int, string>
     */
    private const LINTABLE_TYPES = ['text', 'textarea', 'richText', 'blocks'];

    /**
     * Escape hatch, off only inside a callback.
     *
     * Static, unlike the registries that Phase 1 moved into the container. The
     * difference is lifetime: this is a scoped override restored in a finally
     * block, in the same shape as Model::withoutEvents, not a store that
     * accumulates state across a request.
     */
    private static bool $enabled = true;

    /** @var array<string, LintRule> */
    private array $rules = [];

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly TextExtractor $extractor,
        private readonly array $config,
    ) {
        $this->registerBuiltIns();
    }

    /**
     * Run without linting, for content whose author is not a person.
     *
     * The Phase 6 import reads years of legacy content written before these
     * rules existed. Blocking that import would mean either hand-editing
     * hundreds of entries before they can be stored, or turning the rules off
     * globally and forgetting to turn them back on.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function without(Closure $callback): mixed
    {
        $previous = self::$enabled;
        self::$enabled = false;

        try {
            return $callback();
        } finally {
            self::$enabled = $previous;
        }
    }

    public static function enabled(): bool
    {
        return self::$enabled;
    }

    /** Register an app-defined rule, or replace a built-in. */
    public function extend(LintRule $rule): void
    {
        $this->rules[$rule->handle()] = $rule;
    }

    /**
     * Every issue in an entry, blocking and warning alike.
     *
     * @return array<int, LintIssue>
     */
    public function lint(Entry $entry): array
    {
        if (! self::$enabled || ! (bool) ($this->config['enabled'] ?? true)) {
            return [];
        }

        try {
            $schema = $entry->schema();
        } catch (Throwable) {
            // An entry in a collection the registry does not know about cannot
            // be linted, and refusing to save it would be a worse outcome than
            // letting an em-dash through.
            return [];
        }

        $issues = [];

        foreach ($this->lintableFields($schema) as $field) {
            $issues = [...$issues, ...$this->lintValue(
                $this->valueFor($entry, $field),
                $field->name,
                $field->lintRules(),
            )];
        }

        // SEO title and description are prose, and they are the prose most
        // likely to be pasted in from somewhere else.
        foreach (['title', 'description'] as $key) {
            $issues = [...$issues, ...$this->lintValue(
                data_get($entry->seo, $key),
                "seo.{$key}",
            )];
        }

        return $issues;
    }

    /**
     * Run the rules over one value.
     *
     * @param  array<int, string>  $extraRules  Rules named by the field on top of the global set.
     * @return array<int, LintIssue>
     */
    public function lintValue(mixed $value, string $field, array $extraRules = []): array
    {
        $text = $this->extractor->extract($value);

        if (trim($text) === '') {
            return [];
        }

        $issues = [];

        foreach ($this->activeRules($extraRules) as $handle => $severity) {
            $rule = $this->rules[$handle] ?? null;

            if ($rule === null) {
                continue;
            }

            foreach ($rule->check($text, $field) as $issue) {
                $issues[] = $issue->severity === $severity
                    ? $issue
                    : $issue->withSeverity($severity);
            }
        }

        return $issues;
    }

    /**
     * Only the issues that should stop a save.
     *
     * @param  array<int, LintIssue>  $issues
     * @return array<int, LintIssue>
     */
    public function blocking(array $issues): array
    {
        return array_values(array_filter($issues, fn (LintIssue $issue): bool => $issue->blocking()));
    }

    /**
     * Rule handle => severity, for one field.
     *
     * Config decides the global set. A field naming extra rules adds to it
     * rather than replacing it, so `Field::lint(['no-em-dash'])` cannot
     * accidentally opt a field out of every other rule, which is the trap in
     * the obvious design.
     *
     * @param  array<int, string>  $extraRules
     * @return array<string, string>
     */
    private function activeRules(array $extraRules): array
    {
        $block = (array) ($this->config['block'] ?? []);
        $warn = (array) ($this->config['warn'] ?? []);

        $active = [];

        foreach ($block as $handle) {
            $active[$handle] = LintIssue::BLOCK;
        }

        foreach ($warn as $handle) {
            $active[$handle] ??= LintIssue::WARN;
        }

        foreach ($extraRules as $handle) {
            $active[$handle] ??= LintIssue::BLOCK;
        }

        return $active;
    }

    /** @return array<int, Field> */
    private function lintableFields(CollectionSchema $schema): array
    {
        return array_values(array_filter(
            $schema->allFields(),
            fn (Field $field): bool => in_array($field->type, self::LINTABLE_TYPES, true),
        ));
    }

    /**
     * The authored value, wherever it lives.
     *
     * `title` is a real column rather than a jsonb key, so reading everything
     * from `data` would silently skip the one field every page puts in an h1.
     */
    private function valueFor(Entry $entry, Field $field): mixed
    {
        return in_array($field->name, CollectionSchema::NATIVE_COLUMNS, true)
            ? $entry->getAttribute($field->name)
            : $entry->value($field->name);
    }

    private function registerBuiltIns(): void
    {
        foreach ([
            new NoEmDash,
            new NoHtmlEntities,
            new EnDashRanges,
            new NoFillerWords((array) ($this->config['filler_words'] ?? [])),
            new UkSpelling((array) ($this->config['spellings'] ?? [])),
        ] as $rule) {
            $this->extend($rule);
        }
    }
}
