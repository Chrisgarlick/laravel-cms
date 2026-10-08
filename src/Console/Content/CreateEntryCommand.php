<?php

declare(strict_types=1);

namespace Cg\Cms\Console\Content;

/**
 * A new draft, from markdown, --set values, or both. Never published.
 */
final class CreateEntryCommand extends ContentCommand
{
    protected $signature = 'cms:entry:create
        {collection : The collection handle}
        {--from= : A markdown file; frontmatter keys are field names, the body fills the rich text field}
        {--field= : Which rich text field the markdown body fills}
        {--set=* : name=value, repeatable; seo.title and seo.description work too}
        {--as= : Email of the user to attribute this to (default CMS_CLI_USER)}
        {--json : Machine-readable output}';

    protected $description = 'Create a draft entry';

    public function handle(): int
    {
        $collection = (string) $this->argument('collection');

        return $this->attempt(function () use ($collection): array {
            $fields = collect($this->service()->collections())->firstWhere('handle', $collection)['fields'] ?? [];

            return $this->service()->createDraft($collection, $this->contentInput($fields), $this->userId());
        }, function (array $entry): void {
            $this->components->info("Draft created: {$entry['title']}");
            $this->components->twoColumnDetail('Reference', "{$entry['collection']}/{$entry['slug']}");
            $this->components->twoColumnDetail('Edit and publish', $entry['editUrl']);
            $this->printChecks($entry);
        });
    }
}
