<?php

declare(strict_types=1);

namespace Cg\Cms\Settings;

use Cg\Cms\Schema\Field;
use JsonException;
use RuntimeException;

/**
 * Site details an application declares in a JSON schema, beyond the few
 * settings cg-cms has of its own.
 *
 * The format is kit's section schema (`fields`, each with a `type`, `label`,
 * `help`, `max`...), so kit's site.schema.json drops straight in: the phone
 * number, logo, social profiles and small print kit's sections show wherever a
 * field is bound to one. Each field becomes an ordinary cg-cms Field, so the
 * Settings screen edits it with the same admin field types as a block (a list
 * is a repeater, an image a media field) and validates it with the same rules.
 *
 * A field is stored as the setting `site.<name>`, so a schema field cg-cms
 * already has (`name`) is the same setting, edited where it always was.
 */
final class SiteSchema
{
    /** @var array<int, Field>|null */
    private ?array $fields = null;

    public function __construct(private readonly ?string $path) {}

    public function exists(): bool
    {
        return $this->path !== null && $this->path !== '';
    }

    /**
     * Every field the schema declares, as cg-cms fields.
     *
     * @return array<int, Field>
     */
    public function fields(): array
    {
        if ($this->fields !== null) {
            return $this->fields;
        }

        if (! $this->exists()) {
            return $this->fields = [];
        }

        if (! is_file((string) $this->path)) {
            throw new RuntimeException("The site schema (cg-cms.site_schema) is not a file: {$this->path}");
        }

        try {
            $schema = json_decode((string) file_get_contents((string) $this->path), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("The site schema {$this->path} is not valid JSON: {$e->getMessage()}", 0, $e);
        }

        $fields = [];

        foreach ((array) ($schema['fields'] ?? []) as $name => $spec) {
            if (is_string($name) && is_array($spec) && ($field = self::field($name, $spec)) !== null) {
                $fields[] = $field;
            }
        }

        return $this->fields = $fields;
    }

    /** @return array<int, string> */
    public function names(): array
    {
        return array_map(fn (Field $field): string => $field->name, $this->fields());
    }

    /**
     * One schema field as a cg-cms field. Null for a type with no admin field.
     *
     * Nothing is required: a site detail left empty falls back to config, and
     * a section bound to it shows nothing rather than failing.
     *
     * @param  array<string, mixed>  $spec
     */
    private static function field(string $name, array $spec): ?Field
    {
        $type = (string) ($spec['type'] ?? '');
        $help = isset($spec['help']) ? (string) $spec['help'] : null;

        $field = match ($type) {
            'text' => Field::text($name),
            'textarea', 'rich-text' => Field::textarea($name),
            'url' => Field::url($name),
            'number' => Field::number($name),
            'boolean' => Field::boolean($name),
            'enum', 'heading-level' => Field::select($name),
            'image' => Field::media($name),
            'list' => Field::repeater($name),
            default => null,
        };

        if ($field === null) {
            return null;
        }

        if (isset($spec['label'])) {
            $field->label((string) $spec['label']);
        }

        if (in_array($type, ['text', 'textarea', 'rich-text', 'url'], true) && isset($spec['max'])) {
            $field->maxLength((int) $spec['max']);
        }

        if (in_array($type, ['number', 'list'], true)) {
            if (isset($spec['min'])) {
                $field->min($spec['min'] + 0);
            }

            if (isset($spec['max'])) {
                $field->max($spec['max'] + 0);
            }
        }

        if ($type === 'enum') {
            $options = array_map('strval', (array) ($spec['options'] ?? []));
            $field->options($options)->default($spec['default'] ?? ($options[0] ?? null));
        } elseif ($type === 'heading-level') {
            $field->options(['1', '2', '3', '4'])->default((string) ($spec['default'] ?? '2'));
        } elseif (array_key_exists('default', $spec) && $type !== 'list') {
            $field->default($spec['default']);
        }

        if ($type === 'image' && ($spec['alt'] ?? 'required') === 'required') {
            $field->requireAlt();
        }

        if ($type === 'rich-text' && ! str_contains((string) $help, '*')) {
            $help = trim(($help ?? '').' *Stars* for italics, **double stars** for bold.');
        }

        if ($help !== null && $help !== '') {
            $field->help($help);
        }

        if ($type === 'list') {
            $items = [];

            foreach ((array) ($spec['item'] ?? []) as $itemName => $itemSpec) {
                if (is_string($itemName) && is_array($itemSpec) && ($item = self::field($itemName, $itemSpec)) !== null) {
                    // Inside a row, a required subfield is required: a social
                    // profile with no address is not a profile.
                    if (($itemSpec['required'] ?? false) === true) {
                        $item->required();
                    }

                    $items[] = $item;
                }
            }

            $field->fields($items);

            $label = array_values(array_filter($items, fn (Field $item): bool => $item->type === 'text'))[0] ?? null;

            if ($label !== null) {
                $field->rowLabel($label->name);
            }
        }

        return $field;
    }
}
