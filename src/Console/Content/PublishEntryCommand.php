<?php

declare(strict_types=1);

namespace Cg\Cms\Console\Content;

use Cg\Cms\Content\ContentException;
use Cg\Cms\Models\Entry;
use Illuminate\Support\Str;

/**
 * Publish a draft, or apply a live entry's waiting proposal, through the
 * same brand voice rules and publish gate as the admin.
 *
 * It shows what will change and asks first. --yes skips the question, for
 * scripts, and for Claude Code only when the person has asked it to
 * publish. Creating and updating entries never publish.
 */
final class PublishEntryCommand extends ContentCommand
{
    protected $signature = 'cms:entry:publish
        {reference : collection/slug or collection/id}
        {--yes : Publish without asking}
        {--as= : Email of the user to attribute this to (default CMS_CLI_USER)}
        {--json : Machine-readable output (needs --yes)}';

    protected $description = 'Publish a draft, or apply the change proposed for a live entry';

    public function handle(): int
    {
        return $this->attempt(function (): array {
            $entry = $this->service()->find((string) $this->argument('reference'));
            $hasProposal = $this->service()->proposal($entry) !== null;

            if ($entry->status === 'published' && ! $hasProposal) {
                throw new ContentException('Nothing to publish: the entry is live and no change is waiting.');
            }

            if (! $this->option('yes')) {
                if ($this->option('json')) {
                    throw new ContentException('Add --yes: --json cannot ask for confirmation.');
                }

                $this->preview($entry, $hasProposal);

                if (! $this->confirm($hasProposal ? 'Apply this change to the live page?' : 'Publish this draft?')) {
                    return ['action' => 'cancelled'];
                }
            }

            $userId = $this->userId();

            return $entry->status === 'published'
                ? $this->service()->applyProposal($entry, $userId)
                : $this->service()->publish($entry, $userId);
        }, function (array $result): void {
            match ($result['action']) {
                'cancelled' => $this->components->info('Nothing published.'),
                'applied' => $this->components->info("Applied the proposed change: {$result['title']} is updated."),
                default => $this->components->info("Published: {$result['title']}"),
            };

            if (isset($result['url'])) {
                $this->components->twoColumnDetail('Live', (string) $result['url']);
            }
        });
    }

    private function preview(Entry $entry, bool $hasProposal): void
    {
        $this->components->twoColumnDetail("<options=bold>{$entry->collection}/{$entry->slug}</>", $entry->title);

        if (! $hasProposal) {
            $this->components->twoColumnDetail('Status', 'draft, will be published');

            return;
        }

        foreach ($this->service()->proposalChanges($entry) as $key => [$now, $next]) {
            $this->line("  <options=bold>{$key}</>");
            $this->line('    <fg=red>- '.Str::limit($this->display($now), 160).'</>');
            $this->line('    <fg=green>+ '.Str::limit($this->display($next), 160).'</>');
        }
    }

    private function display(mixed $value): string
    {
        return match (true) {
            $value === null => '(none)',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }
}
