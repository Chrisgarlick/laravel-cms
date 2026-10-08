<?php

declare(strict_types=1);

namespace Cg\Cms\Generator;

use InvalidArgumentException;

/**
 * One field from a generator spec: `name:type[:argument][:flag...]`.
 *
 * ```
 * summary:textarea
 * logo:media:required
 * kind:select:personal|client|internal
 * projects:relation:project:many
 * ```
 *
 * The argument is the option list for a select and the target collection
 * for a relation. Flags are `required` and `many`. Groups, repeaters and
 * blocks are not expressible on one line and are added to the schema by
 * hand after generating.
 */
final class FieldSpec
{
    public const TYPES = ['text', 'textarea', 'richText', 'number', 'url', 'boolean', 'datetime', 'select', 'media', 'relation'];

    /** Shorthands people type. */
    private const ALIASES = ['rich' => 'richText', 'richtext' => 'richText', 'string' => 'text', 'int' => 'number', 'bool' => 'boolean', 'date' => 'datetime', 'image' => 'media'];

    /** Names the generator already writes on every collection. */
    public const RESERVED = ['title', 'slug', 'status', 'published_at', 'seo'];

    /**
     * @param  array<int, string>  $options
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $required = false,
        public readonly bool $many = false,
        public readonly ?string $target = null,
        public readonly array $options = [],
    ) {}

    /** @return array<int, self> */
    public static function parseList(string $specs): array
    {
        $fields = [];

        foreach (array_filter(array_map('trim', explode(',', $specs))) as $spec) {
            $field = self::parse($spec);

            if (isset($fields[$field->name])) {
                throw new InvalidArgumentException("Field [{$field->name}] is listed twice.");
            }

            $fields[$field->name] = $field;
        }

        return array_values($fields);
    }

    public static function parse(string $spec): self
    {
        $parts = array_map('trim', explode(':', $spec));
        $name = (string) array_shift($parts);
        $type = (string) array_shift($parts);
        $type = self::ALIASES[strtolower($type)] ?? $type;

        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Field name [{$name}] must be snake_case, starting with a letter.");
        }

        if (in_array($name, self::RESERVED, true)) {
            throw new InvalidArgumentException("Field [{$name}] is added to every collection already; leave it out.");
        }

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException(sprintf('Field [%s] has unknown type [%s]. Use one of: %s.', $name, $type, implode(', ', self::TYPES)));
        }

        $flags = array_values(array_intersect($parts, ['required', 'many']));
        $arguments = array_values(array_diff($parts, ['required', 'many']));
        $argument = $arguments[0] ?? null;

        if ($type === 'relation' && ($argument === null || $argument === '')) {
            throw new InvalidArgumentException("Relation [{$name}] needs a target collection, e.g. {$name}:relation:project:many.");
        }

        if ($type === 'select' && ($argument === null || $argument === '')) {
            throw new InvalidArgumentException("Select [{$name}] needs options, e.g. {$name}:select:one|two|three.");
        }

        return new self(
            name: $name,
            type: $type,
            required: in_array('required', $flags, true),
            many: in_array('many', $flags, true),
            target: $type === 'relation' ? $argument : null,
            options: $type === 'select' ? array_values(array_filter(explode('|', (string) $argument), fn (string $o): bool => $o !== '')) : [],
        );
    }

    /** The schema line, e.g. `Field::relation('projects')->to('project')->many()`. */
    public function toPhp(): string
    {
        $code = "Field::{$this->type}('{$this->name}')";

        if ($this->required) {
            $code .= '->required()';
        }

        if ($this->type === 'select') {
            $code .= "->options(['".implode("', '", array_map('addslashes', $this->options))."'])->default('".addslashes($this->options[0] ?? '')."')";
        }

        if ($this->type === 'relation') {
            $code .= "->to('{$this->target}')".($this->many ? '->many()' : '');
        }

        if ($this->type === 'media') {
            $code .= '->requireAlt()';
        }

        // The same brand-voice rules every other rich text field on a site
        // built on this package carries.
        if ($this->type === 'richText') {
            $code .= "->lint(['no-em-dash', 'no-html-entities'])";
        }

        if ($this->type === 'boolean') {
            $code .= "->default('0')";
        }

        if (! $this->required && in_array($this->type, ['text', 'url', 'number', 'datetime'], true)) {
            $code .= '->nullable()';
        }

        return $code;
    }

    /** The spec back as a string, for printing the command that would recreate it. */
    public function toSpec(): string
    {
        $argument = match ($this->type) {
            'select' => ':'.implode('|', $this->options),
            'relation' => ':'.$this->target,
            default => '',
        };

        return $this->name.':'.$this->type.$argument.($this->required ? ':required' : '').($this->many ? ':many' : '');
    }
}
