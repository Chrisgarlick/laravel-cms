<?php

declare(strict_types=1);

namespace Cg\Cms\Console\Content;

/** What can be written: every collection and its fields. */
final class CollectionsCommand extends ContentCommand
{
    protected $signature = 'cms:collections {--json : Machine-readable output}';

    protected $description = 'List the content collections and their fields';

    public function handle(): int
    {
        return $this->attempt(fn () => $this->service()->collections(), function (array $collections): void {
            foreach ($collections as $collection) {
                $this->components->twoColumnDetail("<options=bold>{$collection['label']}</> ({$collection['handle']})", (string) ($collection['url'] ?? 'no pages'));
                $this->line('  <fg=gray>'.implode(', ', array_map(fn (array $field): string => $field['name'].':'.$field['type'].(($field['required'] ?? false) ? '*' : ''), $collection['fields'])).'</>');
            }
        });
    }
}
