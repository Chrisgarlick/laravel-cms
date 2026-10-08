<?php

declare(strict_types=1);

namespace Cg\Cms\Console;

use Cg\Cms\Generator\CollectionGenerator;
use Cg\Cms\Generator\FieldSpec;
use Cg\Cms\Schema\CollectionRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Generates a new content type: schema entry, controller, routes, templates
 * and a test, in one go.
 *
 * ```
 * php artisan cms:collection framework \
 *     --label=Frameworks \
 *     --fields="summary:textarea,logo:media,years:number,projects:relation:project:many"
 * ```
 *
 * Shows the plan and asks before writing. `--dry-run` prints it and stops,
 * `--routeless` makes a data-only collection (schema entry only), and
 * existing files are never overwritten.
 */
final class MakeCollectionCommand extends Command
{
    protected $signature = 'cms:collection
        {handle : snake_case and singular, e.g. framework or case_study}
        {--label= : What the admin calls it, e.g. Frameworks. Defaults to the plural of the handle}
        {--fields= : Comma-separated name:type[:argument][:required][:many] specs}
        {--route= : URL for the index page. Defaults to /<plural-handle>}
        {--routeless : Data only: no pages, no controller, no templates}
        {--dry-run : Show what would be written without writing it}
        {--force : Write without asking for confirmation}';

    protected $description = 'Generate a new content type with its pages, templates and test';

    /** Admin paths a collection handle would shadow. */
    private const RESERVED = ['media', 'redirects', 'submissions', 'seo', 'settings', 'schema', 'search', 'boundary', 'signin', 'signout', 'new'];

    public function handle(CollectionRegistry $registry): int
    {
        $handle = (string) $this->argument('handle');

        if (preg_match('/^[a-z][a-z0-9_]*$/', $handle) !== 1) {
            $this->components->error('The handle must be snake_case, starting with a letter: framework, case_study.');

            return self::FAILURE;
        }

        if ($registry->has($handle)) {
            $this->components->error("A collection called [{$handle}] already exists.");

            return self::FAILURE;
        }

        if (in_array($handle, self::RESERVED, true)) {
            $this->components->error("[{$handle}] is an admin screen's URL, so a collection with that handle could never be opened. Pick another.");

            return self::FAILURE;
        }

        try {
            $fields = FieldSpec::parseList((string) $this->option('fields'));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($fields as $field) {
            if ($field->type === 'relation' && ! $registry->has((string) $field->target) && $field->target !== $handle) {
                $this->components->error("Relation [{$field->name}] points at [{$field->target}], which is not a collection. Known: ".implode(', ', $registry->handles()).'.');

                return self::FAILURE;
            }
        }

        $label = (string) ($this->option('label') ?: Str::headline(Str::plural($handle)));
        $route = $this->option('routeless') ? null : '/'.trim((string) ($this->option('route') ?: Str::kebab(Str::camel(Str::plural($handle)))), '/');

        $generator = new CollectionGenerator(
            handle: $handle,
            label: $label,
            fields: $fields,
            routeBase: $route,
            schemaPath: (string) config('cg-cms.schema_path'),
            routesPath: (string) config('cg-cms.generator.routes', base_path('routes/web.php')),
        );

        $plan = $generator->plan();

        $conflicts = array_filter($plan, fn ($file): bool => ! $file->insert && $file->exists());

        if ($conflicts !== []) {
            foreach ($conflicts as $file) {
                $this->components->error('Already exists: '.$this->relative($file->path));
            }

            $this->line('  Nothing was written. Move or delete those files, or pick another handle.');

            return self::FAILURE;
        }

        $this->components->info("New content type: {$label} ({$handle})".($route === null ? ', data only' : ", at {$route}"));

        foreach ($plan as $file) {
            $this->components->twoColumnDetail(
                ($file->insert ? '<fg=yellow>update</> ' : '<fg=green>create</> ').$this->relative($file->path),
                $file->description,
            );
        }

        $this->newLine();
        $this->line('  Schema entry:');
        $this->newLine();
        $this->line($generator->schemaCode());

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

        $this->components->info("{$label} is ready.");

        $steps = ['Open the admin: it is in the sidebar under Content.'];

        if ($route !== null) {
            $steps[] = 'Run the new test: php artisan test --filter='.Str::studly($handle).'PagesTest';
            $steps[] = "Publish an entry and visit {$route}.";
            $steps[] = 'The templates are a starting point: restyle them in resources/views.';
        }

        foreach ($steps as $index => $step) {
            $this->line('  '.($index + 1).'. '.$step);
        }

        return self::SUCCESS;
    }

    private function relative(string $path): string
    {
        return Str::after($path, rtrim(base_path(), '/').'/');
    }
}
