<?php

declare(strict_types=1);

namespace Cg\Cms\Console;

use Cg\Cms\Schema\Collection;
use Cg\Cms\Schema\CollectionRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Diffs promoted fields in config against the live database.
 *
 * Adding a field to a collection needs no migration at all: it is a jsonb key.
 * Only *promoting* a field to an indexed generated column touches the schema,
 * and this writes that migration for you to read before running it.
 *
 * That is the deliberate middle ground between the current CMS, which makes you
 * hand-write SQL for every field change (Kritano issue 11), and a CMS that
 * silently alters production tables.
 */
final class SyncSchemaCommand extends Command
{
    protected $signature = 'cms:sync-schema {--write : Write the migration file rather than only reporting}';

    protected $description = 'Generate a migration for promoted collection fields';

    public function handle(CollectionRegistry $registry): int
    {
        $existing = $this->existingColumns();
        $needed = [];
        $skipped = [];

        foreach ($registry->all() as $handle => $collection) {
            foreach ($collection->promotedFields() as $field) {
                if (in_array($field, Collection::NATIVE_COLUMNS, true)) {
                    $skipped[] = "{$handle}.{$field} (already a first-class column)";

                    continue;
                }

                if (in_array($field, $existing, true)) {
                    continue;
                }

                $definition = $collection->field($field);

                if ($definition === null) {
                    $this->components->error("Collection [{$handle}] promotes unknown field [{$field}].");

                    return self::FAILURE;
                }

                $needed[$field] = $this->sqlType($definition->type);
            }
        }

        foreach ($skipped as $note) {
            $this->components->warn("Skipping {$note}.");
        }

        if ($needed === []) {
            $this->components->info('Schema is in sync. No promoted columns to add.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf('%d promoted column(s) to add:', count($needed)));

        foreach ($needed as $column => $type) {
            $this->components->twoColumnDetail($column, $type);
        }

        if (! $this->option('write')) {
            $this->newLine();
            $this->components->info('Re-run with --write to generate the migration.');

            return self::SUCCESS;
        }

        $path = $this->writeMigration($needed);

        $this->components->info('Migration written.');
        $this->components->twoColumnDetail('Path', $path);
        $this->components->warn('Read it before running migrate. It alters a live table.');

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function existingColumns(): array
    {
        return array_column(
            DB::select("
                select column_name
                from information_schema.columns
                where table_name = 'entries'
            "),
            'column_name',
        );
    }

    private function sqlType(string $fieldType): string
    {
        return match ($fieldType) {
            'number' => 'numeric',
            'datetime' => 'timestamptz',
            // Postgres accepts '0', '1', 'true' and 'false' when casting text
            // to boolean, which covers every shape jsonb ->> can produce here.
            'boolean' => 'boolean',
            default => 'text',
        };
    }

    /** @param array<string, string> $columns */
    private function writeMigration(array $columns): string
    {
        $up = [];
        $down = [];

        foreach ($columns as $column => $type) {
            // Generated columns are maintained by Postgres, so no application
            // code can forget to keep them in step with `data`.
            $cast = $type === 'text' ? '' : '::'.$type;

            $up[] = <<<SQL
                    DB::statement("
                        alter table entries
                            add column {$column} {$type}
                            generated always as ((data ->> '{$column}'){$cast}) stored
                    ");

                    DB::statement('
                        create index entries_collection_{$column}_idx
                            on entries (collection, {$column})
                            where deleted_at is null
                    ');
            SQL;

            $down[] = <<<SQL
                    DB::statement('drop index if exists entries_collection_{$column}_idx');
                    DB::statement('alter table entries drop column if exists {$column}');
            SQL;
        }

        $upBody = implode("\n\n", $up);
        $downBody = implode("\n", $down);
        $name = 'promote_'.implode('_', array_keys($columns)).'_on_entries';
        $filename = now()->format('Y_m_d_His').'_'.Str::snake($name).'.php';
        $path = database_path('migrations/'.$filename);

        $stub = <<<PHP
        <?php

        declare(strict_types=1);

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Support\Facades\DB;

        /**
         * Promoted collection fields, generated by `php artisan cms:sync-schema`.
         *
         * Generated columns are derived from `data` by Postgres itself, so they
         * cannot drift from the authored value.
         */
        return new class extends Migration
        {
            public function up(): void
            {
        {$upBody}
            }

            public function down(): void
            {
        {$downBody}
            }
        };

        PHP;

        file_put_contents($path, $stub);

        return $path;
    }
}
