<?php

declare(strict_types=1);

namespace Cg\Cms\Console\Content;

/** Find entries. */
final class EntriesCommand extends ContentCommand
{
    protected $signature = 'cms:entries
        {collection? : A collection handle, or every collection}
        {--search= : Match on title or slug}
        {--status= : published or draft}
        {--limit=20}
        {--json : Machine-readable output}';

    protected $description = 'List or search entries';

    public function handle(): int
    {
        return $this->attempt(
            fn () => $this->service()->search($this->argument('collection'), $this->option('search'), $this->option('status'), (int) $this->option('limit')),
            function (array $entries): void {
                if ($entries === []) {
                    $this->components->info('Nothing found.');

                    return;
                }

                $this->table(['Reference', 'Title', 'Status', 'Updated'], array_map(fn (array $entry): array => [
                    "{$entry['collection']}/{$entry['slug']}",
                    $entry['title'],
                    $entry['status'],
                    $entry['updatedAt'] ? date('j M Y H:i', strtotime($entry['updatedAt'])) : '',
                ], $entries));
            },
        );
    }
}
