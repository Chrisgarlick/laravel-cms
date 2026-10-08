<?php

declare(strict_types=1);

namespace Cg\Cms\Admin;

use Cg\Cms\Schema\Block;
use ReflectionClass;

/**
 * Finds where a collection, field, block or field group is defined.
 *
 * A text search, not a parser: the schema is a PHP file of fluent calls, and
 * `Collection::make('project')` or `Field::text('role')` is unambiguous
 * enough to find by pattern. Used only by the read-only content model screen
 * to say "defined at app/Cms/schema.php:84", so a near miss costs a line
 * number, never correctness.
 */
final class SchemaLocator
{
    /** @var array<string, array<int, string>> */
    private array $lines = [];

    public function __construct(private readonly string $schemaPath) {}

    /** @return array{file: string, line: int}|null */
    public function collection(string $handle): ?array
    {
        return $this->find($this->schemaPath, "Collection::make('{$handle}')");
    }

    /**
     * The first `Field::type('name')` after the collection's own definition.
     * A field defined in a shared closure (like the built-in blocks' theme
     * select) may resolve to its first textual use, or to nothing.
     *
     * @return array{file: string, line: int}|null
     */
    public function collectionField(string $handle, string $type, string $name): ?array
    {
        $start = $this->collection($handle)['line'] ?? 1;

        // The factory is named after the type (`Field::richText('body')`),
        // except SEO, whose name argument is optional.
        $needle = $type === 'seo' ? 'Field::seo(' : "Field::{$type}('{$name}'";

        $found = $this->find($this->schemaPath, $needle, $start);
        $next = $this->find($this->schemaPath, 'Collection::make(', $start + 1);

        // Past the next collection means it belongs to that one, not this.
        return $found !== null && ($next === null || $found['line'] < $next['line']) ? $found : null;
    }

    /** @return array{file: string, line: int}|null */
    public function block(string $handle): ?array
    {
        return $this->find($this->schemaPath, "Block::make('{$handle}'")
            ?? $this->find($this->packageBlockFile(), "self::make('{$handle}'");
    }

    /** @return array{file: string, line: int}|null */
    public function fieldGroup(string $name): ?array
    {
        return $this->find($this->schemaPath, "FieldGroup::define('{$name}'");
    }

    /** @return array{file: string, line: int}|null */
    private function find(string $path, string $needle, int $fromLine = 1): ?array
    {
        foreach ($this->linesOf($path) as $index => $line) {
            if ($index + 1 >= $fromLine && str_contains($line, $needle)) {
                return ['file' => $this->relative($path), 'line' => $index + 1];
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private function linesOf(string $path): array
    {
        return $this->lines[$path] ??= is_file($path) ? (file($path) ?: []) : [];
    }

    private function packageBlockFile(): string
    {
        return (string) (new ReflectionClass(Block::class))->getFileName();
    }

    /** Relative to the app where possible; the package's own files by package path. */
    private function relative(string $path): string
    {
        $real = realpath($path) ?: $path;
        $base = rtrim((string) realpath(base_path()), '/').'/';

        if (str_starts_with($real, $base)) {
            return substr($real, strlen($base));
        }

        $package = (string) realpath(dirname(__DIR__, 2));

        return str_starts_with($real, $package) ? 'cg-cms/'.ltrim(substr($real, strlen($package)), '/') : $real;
    }
}
