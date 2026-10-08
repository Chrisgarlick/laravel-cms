<?php

declare(strict_types=1);

namespace Cg\Cms\Console\Content;

/**
 * Change an entry. Drafts change in place; a live entry gets a proposal to
 * review in the editor instead.
 */
final class UpdateEntryCommand extends ContentCommand
{
    protected $signature = 'cms:entry:update
        {reference : collection/slug or collection/id}
        {--from= : A markdown file; frontmatter keys are field names, the body fills the rich text field}
        {--field= : Which rich text field the markdown body fills}
        {--set=* : name=value, repeatable; seo.title and seo.description work too}
        {--as= : Email of the user to attribute this to (default CMS_CLI_USER)}
        {--json : Machine-readable output}';

    protected $description = 'Update a draft, or propose a change to a live entry';

    public function handle(): int
    {
        return $this->attempt(function (): array {
            $entry = $this->service()->find((string) $this->argument('reference'));
            $fields = collect($this->service()->collections())->firstWhere('handle', $entry->collection)['fields'] ?? [];

            return $this->service()->update($entry, $this->contentInput($fields), $this->userId());
        }, function (array $result): void {
            if ($result['action'] === 'proposed') {
                $this->components->warn($result['message']);
            } else {
                $this->components->info("Updated: {$result['title']}");
            }

            $this->components->twoColumnDetail('Edit', $result['editUrl']);
            $this->printChecks($result);
        });
    }
}
