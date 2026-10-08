<?php

declare(strict_types=1);

namespace Cg\Cms\Console\Content;

/** One entry: its fields, SEO and current checks. */
final class EntryCommand extends ContentCommand
{
    protected $signature = 'cms:entry
        {reference : collection/slug or collection/id}
        {--json : Machine-readable output}';

    protected $description = 'Show an entry with its fields and checks';

    public function handle(): int
    {
        return $this->attempt(
            fn () => $this->service()->describe($this->service()->find((string) $this->argument('reference'))),
            function (array $entry): void {
                $this->components->twoColumnDetail("<options=bold>{$entry['title']}</>", $entry['status']);
                $this->components->twoColumnDetail('Edit', $entry['editUrl']);

                if ($entry['url']) {
                    $this->components->twoColumnDetail('Live', $entry['url']);
                }

                foreach ($entry['fields'] as $name => $value) {
                    $text = is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES);
                    $this->components->twoColumnDetail($name, mb_strimwidth(trim(strip_tags($text)), 0, 90, '…'));
                }

                if ($entry['proposal']) {
                    $this->components->warn('A proposed change is waiting for review in the editor.');
                }

                $this->printChecks($entry);
            },
        );
    }
}
