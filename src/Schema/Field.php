<?php

declare(strict_types=1);

namespace Cg\Cms\Schema;

use Illuminate\Support\Str;

/**
 * One authored field on a collection.
 *
 * A field knows four things: how it is stored, how it is validated, how the
 * admin renders it, and which lint rules apply to it. Phase 0 needs the first
 * two. The admin resolves the third from a registry keyed by `type`.
 */
final class Field
{
    public const RICH_TEXT_TYPES = ['richText', 'blocks'];

    private ?string $label = null;

    private ?string $help = null;

    private bool $required = false;

    private bool $nullable = false;

    private ?int $maxLength = null;

    private ?string $slugSource = null;

    private ?string $default = null;

    /** @var array<int, string> */
    private array $options = [];

    /** @var array<int, string> */
    private array $lintRules = [];

    private ?string $relatedCollection = null;

    private bool $many = false;

    private bool $requireAlt = false;

    private ?string $preset = null;

    /** @var array<int, Block> */
    private array $allowedBlocks = [];

    /** @var array<int, self> Subfields, for a repeater. */
    private array $subFields = [];

    private ?int $min = null;

    private ?int $max = null;

    private ?string $rowLabel = null;

    private ?string $tab = null;

    private ?string $usesGroup = null;

    private ?bool $sidebar = null;

    /** @var array{field: string, values: array<int, string|int|bool>}|null */
    private ?array $condition = null;

    /** Field types the editor puts in the inspector unless told otherwise. */
    public const SIDEBAR_TYPES = ['slug', 'select', 'boolean', 'datetime', 'number', 'relation'];

    private function __construct(
        public readonly string $name,
        public readonly string $type,
    ) {}

    public static function text(string $name): self
    {
        return new self($name, 'text');
    }

    public static function textarea(string $name): self
    {
        return new self($name, 'textarea');
    }

    public static function richText(string $name): self
    {
        return new self($name, 'richText');
    }

    public static function blocks(string $name): self
    {
        return new self($name, 'blocks');
    }

    public static function slug(string $name): self
    {
        return new self($name, 'slug');
    }

    public static function datetime(string $name): self
    {
        return new self($name, 'datetime');
    }

    public static function number(string $name): self
    {
        return new self($name, 'number');
    }

    public static function url(string $name): self
    {
        return new self($name, 'url');
    }

    public static function media(string $name): self
    {
        return new self($name, 'media');
    }

    public static function select(string $name): self
    {
        return new self($name, 'select');
    }

    public static function boolean(string $name): self
    {
        return new self($name, 'boolean');
    }

    /**
     * A reference to entries in another collection.
     *
     * Stored as an array of slugs rather than IDs, deliberately. Slugs survive
     * a content re-import (the legacy data has no stable IDs to map onto) and
     * they make the stored jsonb readable when debugging. The cost is that
     * renaming a slug must update referrers, which the slug-history observer
     * handles.
     */
    public static function relation(string $name): self
    {
        return new self($name, 'relation');
    }

    /**
     * SEO overrides: title, description, canonical, OG image, noindex.
     *
     * Stored in the `seo` column rather than `data`, since every collection has
     * one and the renderer always reads it.
     */
    public static function seo(string $name = 'seo'): self
    {
        return new self($name, 'seo');
    }

    /**
     * A repeating group of fields.
     *
     * Stored as a jsonb array of objects, one per row. The subfields are
     * ordinary `Field` instances, so every field type works inside a repeater
     * including rich text, and each one renders through the same admin
     * registry as a top-level field.
     *
     * This is the field type the schema was working around in four places
     * before it existed: `columns` and `offer-cards` flatten their rows into
     * `column1_*` and `card1_*`, and `service.includes` and
     * `proof-strip.metrics` stuff a list into a textarea, one per line. Those
     * four stay as they are until after the Phase 6 import, because the
     * flattened shape is what the legacy content is stored in and reshaping it
     * would mean writing a converter for content that is about to be imported
     * once. New content shapes use this.
     */
    public static function repeater(string $name): self
    {
        return new self($name, 'repeater');
    }

    /**
     * A named set of fields stored together as one object.
     *
     * `Field::group('cta')->fields([Field::text('label'), Field::url('url')])`
     * stores `{"cta": {"label": "...", "url": "..."}}`, and a template reads
     * `$entry->data['cta']['label']`. The ACF group field.
     *
     * For a set used in more than one place, define it once with
     * FieldGroup::define() and pull it in with uses().
     */
    public static function group(string $name): self
    {
        return new self($name, 'group');
    }

    /**
     * Group: take this group's fields from a reusable definition.
     *
     * Resolved immediately, so a typo in the name fails when the schema loads
     * rather than when somebody opens the editor.
     */
    public function uses(string $group): self
    {
        $this->subFields = FieldGroup::fields($group);
        $this->usesGroup = $group;

        return $this;
    }

    /**
     * Which editor tab this field sits on.
     *
     * Fields with no tab go on the first, "Content". SEO is always its own tab.
     */
    public function tab(string $tab): self
    {
        $this->tab = $tab;

        return $this;
    }

    /** The reusable group this group's fields came from, if any. */
    public function usedGroup(): ?string
    {
        return $this->usesGroup;
    }

    public function tabName(): ?string
    {
        return $this->tab;
    }

    /**
     * Put this field in the editor's inspector, beside the writing area.
     *
     * Short settings (selects, toggles, dates, relations) go there by default;
     * this overrides that in either direction.
     */
    public function sidebar(bool $sidebar = true): self
    {
        $this->sidebar = $sidebar;

        return $this;
    }

    public function inSidebar(): bool
    {
        return $this->sidebar ?? in_array($this->type, self::SIDEBAR_TYPES, true);
    }

    /**
     * Only show this field when a sibling field has one of the given values.
     *
     * `Field::text('client_name')->showWhen('kind', 'client')`. A hidden
     * field keeps whatever it held, so switching back loses nothing; and a
     * required field is only required while it is shown.
     *
     * @param  string|int|bool|array<int, string|int|bool>  $values
     */
    public function showWhen(string $field, string|int|bool|array $values): self
    {
        $this->condition = ['field' => $field, 'values' => array_values((array) $values)];

        return $this;
    }

    /** @return array{field: string, values: array<int, string|int|bool>}|null */
    public function condition(): ?array
    {
        return $this->condition;
    }

    /**
     * What the editor calls this field.
     *
     * Defaults to the field name in headline case, which is right often enough
     * that setting it everywhere would be noise, and wrong often enough that it
     * has to be overridable: `client_descriptor` is not a label anybody wants
     * to read, and `seo` should say what it is.
     */
    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * A line of guidance under the input.
     *
     * The place to put the reason a field exists. `disclosure` on a project is
     * the example that justifies this: what the three options mean is not
     * guessable from their names, and getting it wrong publishes a client's
     * name.
     */
    public function help(string $help): self
    {
        $this->help = $help;

        return $this;
    }

    public function labelText(): string
    {
        return $this->label ?? Str::headline($this->name);
    }

    public function helpText(): ?string
    {
        return $this->help;
    }

    public function maxLengthValue(): ?int
    {
        return $this->maxLength;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function required(): self
    {
        $this->required = true;

        return $this;
    }

    public function nullable(): self
    {
        $this->nullable = true;

        return $this;
    }

    public function maxLength(int $length): self
    {
        $this->maxLength = $length;

        return $this;
    }

    /** Derive this slug from another field when one is not supplied. */
    public function from(string $field): self
    {
        $this->slugSource = $field;

        return $this;
    }

    public function default(string $value): self
    {
        $this->default = $value;

        return $this;
    }

    /** @param array<int, string> $options */
    public function options(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    /** Target collection for a relation field. */
    public function to(string $collection): self
    {
        $this->relatedCollection = $collection;

        return $this;
    }

    /** A relation that holds many references rather than one. */
    public function many(): self
    {
        $this->many = true;

        return $this;
    }

    /** Media field: require alt text. Accessibility and SEO, enforced by schema. */
    public function requireAlt(): self
    {
        $this->requireAlt = true;

        return $this;
    }

    /** Media field: the variant preset to render by default. */
    public function preset(string $preset): self
    {
        $this->preset = $preset;

        return $this;
    }

    /**
     * Blocks field: which block types may be used.
     *
     * @param  array<int, Block>  $blocks
     */
    public function allow(array $blocks): self
    {
        $this->allowedBlocks = $blocks;

        return $this;
    }

    /** @return array<int, Block> */
    public function allowedBlocks(): array
    {
        return $this->allowedBlocks;
    }

    /**
     * Repeater: the fields each row holds.
     *
     * @param  array<int, self>  $fields
     */
    public function fields(array $fields): self
    {
        $this->subFields = $fields;

        return $this;
    }

    /** @return array<int, self> */
    public function subFields(): array
    {
        return $this->subFields;
    }

    /** Repeater: how many rows are allowed. */
    public function min(int $rows): self
    {
        $this->min = $rows;

        return $this;
    }

    public function max(int $rows): self
    {
        $this->max = $rows;

        return $this;
    }

    /**
     * Repeater: which subfield to show as a row's summary when collapsed.
     *
     * A collapsed row showing "Row 3" is a row you have to open to identify.
     * Naming the field that holds its title makes a long list scannable, which
     * is the entire reason for collapsing in the first place.
     */
    public function rowLabel(string $field): self
    {
        $this->rowLabel = $field;

        return $this;
    }

    public function rowLabelField(): ?string
    {
        return $this->rowLabel;
    }

    public function minRows(): ?int
    {
        return $this->min;
    }

    public function maxRows(): ?int
    {
        return $this->max;
    }

    /** @param array<int, string> $rules */
    public function lint(array $rules): self
    {
        $this->lintRules = $rules;

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function slugSource(): ?string
    {
        return $this->slugSource;
    }

    public function defaultValue(): ?string
    {
        return $this->default;
    }

    /** @return array<int, string> */
    public function selectOptions(): array
    {
        return $this->options;
    }

    /** @return array<int, string> */
    public function lintRules(): array
    {
        return $this->lintRules;
    }

    /** Rich text is pre-rendered to HTML on save, never at request time. */
    public function isRichText(): bool
    {
        return in_array($this->type, self::RICH_TEXT_TYPES, true);
    }

    /**
     * Laravel validation rules for this field.
     *
     * Note the `nullable` alongside `sometimes` for optional fields, which is
     * load-bearing rather than belt and braces. `sometimes` only skips
     * validation when the key is *absent*, and an editor form posts every
     * field it renders, empty ones included. Without `nullable`, an untouched
     * optional media field arrives as `featured_image: null`, clears the
     * `sometimes` gate because the key is present, and then fails `array`.
     *
     * The failure is badly disproportionate: one empty optional field rejects
     * the whole request, so an editor who changed the body watches the save
     * do nothing and the only clue is an error message next to a field they
     * never touched.
     *
     * A required field keeps `required` and no `nullable`, which is the one
     * case where an empty value genuinely should fail.
     *
     * @return array<int, string>
     */
    public function validationRules(string $prefix = ''): array
    {
        $rules = $this->required
            ? ['required']
            : ['sometimes', 'nullable'];

        // Required only while shown. The dependency is a sibling, so it takes
        // the same prefix this field sits under (`cta.style`, `rows.*.kind`).
        if ($this->required && $this->condition !== null) {
            $dependency = $prefix === '' ? $this->condition['field'] : $prefix.'.'.$this->condition['field'];
            $values = array_map(
                fn (string|int|bool $value): string => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
                $this->condition['values'],
            );

            $rules = ['required_if:'.$dependency.','.implode(',', $values), 'nullable'];
        }

        $rules[] = match ($this->type) {
            'number' => 'numeric',
            'datetime' => 'date',
            'boolean' => 'boolean',
            'url' => 'string',
            'richText', 'blocks', 'seo', 'repeater', 'group' => 'array',
            'relation' => $this->many ? 'array' : 'string',
            'media' => 'array',
            default => 'string',
        };

        // maxLength counts characters, so it belongs on text. On an array
        // field Laravel's `max` counts elements instead, which would silently
        // turn a 300-character limit into a 300-row limit.
        if ($this->maxLength !== null && ! in_array($this->type, ['richText', 'blocks', 'seo', 'repeater', 'group', 'media'], true)) {
            $rules[] = 'max:'.$this->maxLength;
        }

        if ($this->type === 'select' && $this->options !== []) {
            $rules[] = 'in:'.implode(',', $this->options);
        }

        if ($this->type === 'repeater') {
            if ($this->min !== null) {
                $rules[] = 'min:'.$this->min;
            }

            if ($this->max !== null) {
                $rules[] = 'max:'.$this->max;
            }
        }

        return $rules;
    }

    /**
     * Rules for the fields inside this one.
     *
     * Returns wildcard-keyed rules (`faqs.*.question`) that a collection
     * merges into its own set, so Laravel validates every row of a repeater
     * without anything having to know how many rows there are.
     *
     * Blocks are absent on purpose. Which rules apply to a block item depends
     * on the `type` that item declares, and a wildcard cannot express that.
     * They are validated by ValidBlocks instead.
     *
     * @return array<string, array<int, string>>
     */
    public function nestedValidationRules(string $prefix = ''): array
    {
        if ($this->type === 'media') {
            return $this->mediaValidationRules($prefix === '' ? $this->name : $prefix.'.'.$this->name);
        }

        if (! in_array($this->type, ['repeater', 'group'], true) || $this->subFields === []) {
            return [];
        }

        // A repeater's rows are a list (`faqs.*.question`); a group is one
        // object (`cta.label`).
        $base = ($prefix === '' ? $this->name : $prefix.'.'.$this->name).($this->type === 'repeater' ? '.*' : '');
        $rules = [];

        foreach ($this->subFields as $field) {
            $rules[$base.'.'.$field->name] = $field->validationRules($base);

            // A repeater inside a repeater is legal and produces
            // `rows.*.inner.*.field`, which Laravel handles natively.
            $rules = [...$rules, ...$field->nestedValidationRules($base)];
        }

        return $rules;
    }

    /**
     * A media value: a library reference that exists, and alt text if required.
     *
     * `requireAlt()` used to be enforced only by the browser, which means not
     * at all for anything arriving another way. Here it holds for the admin,
     * for blocks (ValidBlocks merges these) and for repeater rows, whose
     * wildcard Laravel substitutes into `required_with` itself.
     *
     * A reference to a deleted image fails here rather than saving a hole into
     * the page.
     *
     * @return array<string, array<int, string>>
     */
    private function mediaValidationRules(string $base): array
    {
        $rules = [
            $base.'.id' => ['sometimes', 'nullable', 'string', 'exists:media,uuid'],
            $base.'.src' => ['sometimes', 'nullable', 'string', 'max:2048'],
            $base.'.alt' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];

        // Without `sometimes`, which skips a rule when the key is absent, and
        // an absent alt is precisely the case this exists to catch.
        if ($this->requireAlt) {
            $rules[$base.'.alt'] = ['required_with:'.$base.'.id,'.$base.'.src', 'nullable', 'string', 'max:255'];
        }

        return $rules;
    }

    public function relatedCollection(): ?string
    {
        return $this->relatedCollection;
    }

    public function isMany(): bool
    {
        return $this->many;
    }

    public function altRequired(): bool
    {
        return $this->requireAlt;
    }

    public function mediaPreset(): ?string
    {
        return $this->preset;
    }

    /** SEO lives in its own column, not in `data`. */
    public function isSeo(): bool
    {
        return $this->type === 'seo';
    }

    /**
     * Serialised shape handed to the admin's field registry.
     *
     * One React component per `type`, resolved from a registry. Adding a field
     * type is one PHP class plus one component, registered in both.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'label' => $this->labelText(),
            'help' => $this->help,
            'required' => $this->required,
            'nullable' => $this->nullable,
            'maxLength' => $this->maxLength,
            'slugSource' => $this->slugSource,
            'default' => $this->default,
            'options' => $this->options,
            'lint' => $this->lintRules,
            'relatedCollection' => $this->relatedCollection,
            'many' => $this->many,
            'requireAlt' => $this->requireAlt,
            'preset' => $this->preset,

            // Full field schemas, not just handles. The block canvas renders
            // each block's own fields through the same registry as a
            // top-level field, so it needs the same description of them.
            'allowedBlocks' => array_map(
                fn (Block $block): array => [
                    'handle' => $block->handle,
                    'label' => $block->label,
                    'fields' => array_map(
                        fn (self $field): array => $field->toArray(),
                        $block->allFields(),
                    ),
                ],
                $this->allowedBlocks,
            ),

            'fields' => array_map(fn (self $field): array => $field->toArray(), $this->subFields),
            'min' => $this->min,
            'max' => $this->max,
            'rowLabel' => $this->rowLabel,
            'tab' => $this->tab,
            'sidebar' => $this->inSidebar(),
            'condition' => $this->condition,
            'uses' => $this->usesGroup,
        ];
    }
}
