<?php

declare(strict_types=1);

namespace Cg\Cms\Generator;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Plans a new block: its definition in the application's blocks file, its
 * Blade template, and a test that renders it. Writes nothing itself.
 *
 * The first block an application generates also switches its blocks fields
 * from `Block::builtIns()` to `[...Block::builtIns(), ...Block::app()]`, so
 * the new block can be added to a page straight away.
 */
final class BlockGenerator
{
    /**
     * @param  array<int, FieldSpec>  $fields
     */
    public function __construct(
        private readonly string $handle,
        private readonly string $label,
        private readonly array $fields,
        private readonly string $blocksPath,
        private readonly string $schemaPath,
    ) {}

    /** @return array<int, PlannedFile> */
    public function plan(): array
    {
        $files = [
            new PlannedFile($this->blocksPath, $this->blocksFile(), is_file($this->blocksPath) ? 'Add the block definition' : 'Create the app blocks file with this block', insert: is_file($this->blocksPath)),
            new PlannedFile(resource_path("views/vendor/cgcms/blocks/{$this->handle}.blade.php"), $this->render('block.blade', ['handle' => $this->handle, 'label' => $this->label, 'fields' => $this->fieldMarkup()]), 'Block template'),
            new PlannedFile(base_path('tests/Feature/'.Str::studly($this->handle).'BlockTest.php'), $this->render('block-test', $this->testReplacements()), 'Test that the block renders'),
        ];

        $schema = (string) file_get_contents($this->schemaPath);

        if (str_contains($schema, '->allow(Block::builtIns())')) {
            $files[] = new PlannedFile(
                $this->schemaPath,
                str_replace('->allow(Block::builtIns())', '->allow([...Block::builtIns(), ...Block::app()])', $schema),
                'Let blocks fields use the app\'s own blocks too',
                insert: true,
            );
        }

        return $files;
    }

    public function blockCode(): string
    {
        $lines = ["    Block::make('{$this->handle}', '".addslashes($this->label)."')->fields(["];

        foreach ($this->fields as $field) {
            $lines[] = "        {$field->toPhp()},";
        }

        $lines[] = "        Field::select('theme')->options(['light', 'dark'])->default('light'),";
        $lines[] = '    ]),';

        return implode("\n", $lines)."\n";
    }

    private function blocksFile(): string
    {
        if (! is_file($this->blocksPath)) {
            return (string) file_get_contents(CollectionGenerator::stubPath('blocks-file'))
                ."\n".$this->blockCode()."\n];\n";
        }

        $source = (string) file_get_contents($this->blocksPath);
        $position = strrpos($source, '];');

        if ($position === false) {
            throw new RuntimeException("Could not find the end of the blocks array in {$this->blocksPath}.");
        }

        return rtrim(substr($source, 0, $position))."\n\n".$this->blockCode()."\n".substr($source, $position);
    }

    private function fieldMarkup(): string
    {
        $snippets = [];

        foreach ($this->fields as $field) {
            $snippets[] = $this->render('block-fields/'.$field->type, [
                'name' => $field->name,
                'label' => Str::headline($field->name),
            ]);
        }

        return rtrim(implode("\n", array_filter($snippets, fn (string $snippet): bool => trim($snippet) !== '')));
    }

    /** @return array<string, string> */
    private function testReplacements(): array
    {
        $sample = [];

        foreach ($this->fields as $field) {
            $value = match ($field->type) {
                'text', 'textarea' => "'Sample ".strtolower(Str::headline($field->name))."'",
                'url' => "'https://example.com'",
                'number' => '3',
                'boolean' => 'true',
                'select' => "'".addslashes($field->options[0] ?? '')."'",
                default => null,
            };

            if ($value !== null) {
                $sample[] = "            '{$field->name}' => {$value},";
            }
        }

        $first = collect($this->fields)->first(fn (FieldSpec $field): bool => in_array($field->type, ['text', 'textarea'], true));

        return [
            'handle' => $this->handle,
            'studly' => Str::studly($this->handle),
            'label' => $this->label,
            'sample' => implode("\n", $sample),
            'expect' => $first === null ? '' : 'Sample '.strtolower(Str::headline($first->name)),
        ];
    }

    /** @param  array<string, string>  $replacements */
    private function render(string $stub, array $replacements): string
    {
        $contents = (string) file_get_contents(CollectionGenerator::stubPath($stub));

        foreach ($replacements as $key => $value) {
            $contents = str_replace("[[{$key}]]", $value, $contents);
        }

        return (string) preg_replace("/^[ \t]*\n(?=[ \t]*\n)/m", '', $contents);
    }
}
