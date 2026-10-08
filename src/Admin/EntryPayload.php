<?php

declare(strict_types=1);

namespace Cg\Cms\Admin;

use Cg\Cms\Models\Entry;
use Cg\Cms\Schema\Collection as CollectionSchema;
use Cg\Cms\Schema\Field;

/**
 * Moves values between an editor form and an entry.
 *
 * One place, because the split is not obvious and getting it wrong is silent.
 * `title`, `slug`, `status`, `published_at` and `sort_order` are real columns;
 * everything else is a key in `data`; SEO is its own column. A form posts them
 * all flat, and an entry stores them in three places.
 *
 * Only fields the schema declares are ever read from the request. That is the
 * mass-assignment guard: `Entry` is `$guarded = []`, so trusting the request
 * shape would let a form post `collection` or `created_by` and be believed.
 */
final class EntryPayload
{
    /**
     * Split a flat form payload into the attributes an entry takes.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function toAttributes(CollectionSchema $schema, array $input): array
    {
        $attributes = [];
        $data = [];

        foreach ($schema->allFields() as $field) {
            if ($field->isSeo()) {
                continue;
            }

            // A field absent from the payload is left alone rather than set to
            // null. Autosave posts partial payloads, and a partial save that
            // blanked every untouched field would be a very expensive bug.
            if (! array_key_exists($field->name, $input)) {
                continue;
            }

            $value = $this->cast($field, $input[$field->name]);

            if (in_array($field->name, CollectionSchema::NATIVE_COLUMNS, true)) {
                $attributes[$field->name] = $value;

                continue;
            }

            $data[$field->name] = $value;
        }

        if ($data !== []) {
            $attributes['data'] = $data;
        }

        if (array_key_exists('seo', $input) && is_array($input['seo'])) {
            $attributes['seo'] = $this->seo($input['seo']);
        }

        return $attributes;
    }

    /**
     * Merge a partial payload over what the entry already holds.
     *
     * `data` and `seo` are jsonb columns, so assigning a partial array would
     * replace the document rather than update it. Autosave depends on this
     * being a merge.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function merged(Entry $entry, array $attributes): array
    {
        foreach (['data', 'seo'] as $column) {
            if (array_key_exists($column, $attributes)) {
                $attributes[$column] = [
                    ...(array) $entry->getAttribute($column),
                    ...$attributes[$column],
                ];
            }
        }

        return $attributes;
    }

    /**
     * The entry as the editor form expects it: flat, one key per field.
     *
     * @return array<string, mixed>
     */
    public function toForm(Entry $entry, CollectionSchema $schema): array
    {
        $values = [];

        foreach ($schema->allFields() as $field) {
            if ($field->isSeo()) {
                $values[$field->name] = $this->seo((array) $entry->seo);

                continue;
            }

            $values[$field->name] = in_array($field->name, CollectionSchema::NATIVE_COLUMNS, true)
                ? $this->fromColumn($entry, $field)
                : $entry->value($field->name, $this->blankFor($field));
        }

        return $values;
    }

    /**
     * A blank entry, with defaults applied.
     *
     * @return array<string, mixed>
     */
    public function blank(CollectionSchema $schema): array
    {
        $values = [];

        foreach ($schema->allFields() as $field) {
            $values[$field->name] = $field->isSeo()
                ? $this->seo([])
                : ($field->defaultValue() ?? $this->blankFor($field));
        }

        return $values;
    }

    private function fromColumn(Entry $entry, Field $field): mixed
    {
        $value = $entry->getAttribute($field->name);

        // Datetimes go to the client as ISO 8601 and come back the same way.
        // A locale-formatted string would be ambiguous in both directions.
        return $field->type === 'datetime' && $value !== null
            ? $value->toIso8601String()
            : $value;
    }

    /**
     * Coerce a posted value into what the column expects.
     *
     * HTML forms and JSON both arrive with types the database will not take
     * as-is: an empty string for a nullable date is the common one, and
     * Postgres rejects it rather than treating it as null.
     */
    private function cast(Field $field, mixed $value): mixed
    {
        if ($value === '' && $field->type !== 'text' && $field->type !== 'textarea') {
            return null;
        }

        // Trimmed here rather than by middleware, which is skipped for entry
        // saves so rich text keeps its spacing (see CgCmsServiceProvider).
        if (is_string($value) && in_array($field->type, ['text', 'textarea', 'slug', 'url'], true)) {
            $value = trim($value);
        }

        return match ($field->type) {
            'boolean' => (bool) $value,
            'number' => $value === null ? null : (is_numeric($value) ? $value + 0 : null),
            'relation' => $field->isMany() ? array_values((array) $value) : $value,
            default => $value,
        };
    }

    /**
     * The SEO document, with every key present.
     *
     * A fixed shape in both directions so the client never has to branch on a
     * missing key, and so an entry saved before a key existed does not make
     * the panel render undefined.
     *
     * @param  array<string, mixed>  $seo
     * @return array<string, mixed>
     */
    private function seo(array $seo): array
    {
        return [
            'title' => (string) ($seo['title'] ?? ''),
            'description' => (string) ($seo['description'] ?? ''),
            'canonical' => (string) ($seo['canonical'] ?? ''),
            'og_image' => $seo['og_image'] ?? null,

            // Comma-separated, primary first. The first is what the editor's
            // keyword checks look for; SeoResolver already reads the rest.
            'keywords' => is_array($seo['keywords'] ?? null)
                ? implode(', ', array_map('strval', $seo['keywords']))
                : (string) ($seo['keywords'] ?? ''),
            'noindex' => (bool) ($seo['noindex'] ?? false),
            'nofollow' => (bool) ($seo['nofollow'] ?? false),
        ];
    }

    private function blankFor(Field $field): mixed
    {
        return match ($field->type) {
            'boolean' => false,
            'blocks' => [],
            'relation' => $field->isMany() ? [] : null,
            'richText' => null,
            'media' => null,
            'number', 'datetime' => null,
            default => '',
        };
    }

    /**
     * Whether a request payload holds a TipTap document anywhere in it.
     *
     * @param  array<mixed>  $input
     */
    public static function containsDocument(array $input): bool
    {
        if (($input['type'] ?? null) === 'doc' && array_key_exists('content', $input)) {
            return true;
        }

        foreach ($input as $value) {
            if (is_array($value) && self::containsDocument($value)) {
                return true;
            }
        }

        return false;
    }
}
