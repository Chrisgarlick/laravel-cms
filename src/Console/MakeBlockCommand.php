<?php

declare(strict_types=1);

namespace Cg\Cms\Console;

use Cg\Cms\Generator\BlockGenerator;
use Cg\Cms\Generator\FieldSpec;
use Cg\Cms\Schema\BlockRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Generates a page block: definition, template and test.
 *
 * ```
 * php artisan cms:block testimonial --fields="quote:textarea:required,name:text,role:text,photo:media"
 * ```
 *
 * Shows the plan and asks before writing; `--dry-run` only shows it. Never
 * overwrites a file. Every block gets a light/dark `theme` select, like the
 * built-in ones.
 */
final class MakeBlockCommand extends Command
{
    protected $signature = 'cms:block
        {handle : kebab-case, e.g. testimonial or logo-wall}
        {--label= : What the editor calls it. Defaults to the handle in words}
        {--fields= : Comma-separated name:type[:argument][:required] specs}
        {--dry-run : Show what would be written without writing it}
        {--force : Write without asking for confirmation}';

    protected $description = 'Generate a page block with its template and test';

    public function handle(BlockRegistry $registry): int
    {
        $handle = (string) $this->argument('handle');

        if (preg_match('/^[a-z][a-z0-9-]*$/', $handle) !== 1) {
            $this->components->error('The handle must be kebab-case, starting with a letter: testimonial, logo-wall.');

            return self::FAILURE;
        }

        if ($registry->has($handle)) {
            $this->components->error("A block called [{$handle}] already exists.");

            return self::FAILURE;
        }

        try {
            $fields = FieldSpec::parseList((string) $this->option('fields'));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($fields as $field) {
            if ($field->type === 'relation') {
                $this->components->error("Blocks cannot hold relation fields yet ([{$field->name}]). Use a block that lists a collection, like case-study-grid, as the model.");

                return self::FAILURE;
            }

            if ($field->name === 'theme') {
                $this->components->error('Every block gets a theme field already; leave it out.');

                return self::FAILURE;
            }
        }

        $label = (string) ($this->option('label') ?: Str::headline($handle));

        $generator = new BlockGenerator(
            handle: $handle,
            label: $label,
            fields: $fields,
            blocksPath: (string) config('cg-cms.blocks_path'),
            schemaPath: (string) config('cg-cms.schema_path'),
        );

        $plan = $generator->plan();

        foreach (array_filter($plan, fn ($file): bool => ! $file->insert && $file->exists()) as $file) {
            $this->components->error('Already exists: '.Str::after($file->path, base_path().'/').'. Nothing was written.');

            return self::FAILURE;
        }

        $this->components->info("New block: {$label} ({$handle})");

        foreach ($plan as $file) {
            $this->components->twoColumnDetail(
                ($file->insert ? '<fg=yellow>update</> ' : '<fg=green>create</> ').Str::after($file->path, base_path().'/'),
                $file->description,
            );
        }

        $this->newLine();
        $this->line($generator->blockCode());

        if ($this->option('dry-run')) {
            $this->components->warn('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Write these files?', true)) {
            $this->components->warn('Nothing was written.');

            return self::SUCCESS;
        }

        foreach ($plan as $file) {
            if (! is_dir(dirname($file->path))) {
                mkdir(dirname($file->path), 0755, true);
            }

            file_put_contents($file->path, $file->contents);
        }

        $this->components->info("{$label} is ready. Add it to any page from the block picker.");
        $this->line('  Restyle it in resources/views/vendor/cgcms/blocks/'.$handle.'.blade.php');
        $this->line('  Run its test: php artisan test --filter='.Str::studly($handle).'BlockTest');

        return self::SUCCESS;
    }
}
