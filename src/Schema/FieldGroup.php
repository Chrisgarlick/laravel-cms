<?php

declare(strict_types=1);

namespace Cg\Cms\Schema;

use Closure;
use InvalidArgumentException;

/**
 * Reusable sets of fields, defined once and used anywhere.
 *
 * ```php
 * FieldGroup::define('cta', fn () => [
 *     Field::text('label')->required(),
 *     Field::url('url')->required(),
 *     Field::select('style')->options(['primary', 'secondary'])->default('primary'),
 * ]);
 *
 * Field::group('hero_cta')->uses('cta');
 * ```
 *
 * The definition is a closure so every use gets its own Field objects: a
 * field tweaked in one place (`->label()`, `->help()`) must not change it
 * everywhere else.
 */
final class FieldGroup
{
    /** @var array<string, Closure(): array<int, Field>> */
    private static array $definitions = [];

    /** @param  Closure(): array<int, Field>  $fields */
    public static function define(string $name, Closure $fields): void
    {
        self::$definitions[$name] = $fields;
    }

    /** @return array<int, Field> */
    public static function fields(string $name): array
    {
        if (! isset(self::$definitions[$name])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown field group [%s]. Defined groups: %s. Define it with FieldGroup::define() before the schema uses it.',
                $name,
                self::$definitions === [] ? 'none' : implode(', ', array_keys(self::$definitions)),
            ));
        }

        return (self::$definitions[$name])();
    }

    /** @return array<int, string> */
    public static function names(): array
    {
        return array_keys(self::$definitions);
    }

    public static function has(string $name): bool
    {
        return isset(self::$definitions[$name]);
    }

    /** For tests. */
    public static function flush(): void
    {
        self::$definitions = [];
    }
}
