<?php

declare(strict_types=1);

namespace Cg\Cms\Content;

use Cg\Cms\Cache\CachePurger;
use Cg\Cms\Jobs\RegenerateSeoFiles;
use Cg\Cms\Lint\Linter;
use Cg\Cms\Lint\LintIssue;
use Cg\Cms\Models\Entry;
use Cg\Cms\Models\EntryRevision;
use Cg\Cms\Models\Redirect;
use Cg\Cms\Schema\BlockRegistry;
use Cg\Cms\Schema\Field;
use Cg\Cms\Seo\Checks\EntrySeoChecks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Everything that must happen when content changes.
 *
 * Render on save, then purge on saved. Doing the rendering here rather than in
 * a template is what makes a page view cost one row read and no parsing.
 */
final class EntryObserver
{
    public function __construct(
        private readonly TiptapRenderer $renderer,
        private readonly CachePurger $purger,
        private readonly BlockRegistry $blocks,
        private readonly Linter $linter,
        private readonly EntrySeoChecks $seoChecks,
    ) {}

    public function saving(Entry $entry): void
    {
        $this->fillSlug($entry);
        $this->lint($entry);
        $this->renderRichText($entry);
        $this->gatePublishing($entry);
    }

    /**
     * Render rich text into an entry that is not being saved.
     *
     * For the editor's live panel, which checks the headings and links of
     * content that exists only in the form. Same walk as a save, so what the
     * panel inspects is what a save would store.
     */
    public function prerender(Entry $entry): void
    {
        $this->renderRichText($entry);
    }

    /**
     * Refuse to publish an entry that fails a publish-blocking SEO check.
     *
     * After rendering, because the checks read the rendered HTML. Drafts are
     * never blocked: an unfinished page is allowed to have unfinished
     * headings, and a draft that cannot be saved is a draft that gets kept in
     * a text file instead.
     *
     * Off whenever linting is off, so the Phase 6 import, which runs inside
     * Linter::without(), can store legacy content as it was published.
     */
    private function gatePublishing(Entry $entry): void
    {
        if ($entry->status !== 'published' || ! Linter::enabled()) {
            return;
        }

        $blocking = $this->seoChecks->blockingPublish($entry);

        if ($blocking === []) {
            return;
        }

        $messages = [];

        foreach ($blocking as $issue) {
            $messages[$issue->field][] = $issue->describe().' This stops it being published; it can still be saved as a draft.';
        }

        throw ValidationException::withMessages($messages);
    }

    public function saved(Entry $entry): void
    {
        $this->recordSlug($entry);
        $this->snapshot($entry);

        // Purge synchronously so a stale page is never served, even briefly.
        // Re-warming is queued, because that can afford to wait.
        $this->purger->purge($entry);

        $this->regenerateSeoFiles($entry);
    }

    public function deleted(Entry $entry): void
    {
        $this->purger->purge($entry);

        $this->regenerateSeoFiles($entry);
    }

    /**
     * Queue a rebuild of the sitemap, llms.txt and the feed.
     *
     * Only for entries that have a URL of their own. `proof_metric` has no
     * detail page, so saving one cannot change any of these files, and
     * dispatching anyway would mean the debounce window is permanently open on
     * a site where that content is edited often.
     *
     * The job is unique for a minute, so a batch of saves produces one rebuild.
     */
    private function regenerateSeoFiles(Entry $entry): void
    {
        if (! config('cg-cms.seo.regenerate_on_publish', true) || $entry->routeless()) {
            return;
        }

        RegenerateSeoFiles::dispatch()->delay(now()->addSeconds(30));
    }

    /**
     * Refuse the save if the content breaks a blocking brand voice rule.
     *
     * Deliberately in the observer rather than in a form request, so it holds
     * whatever writes the entry: the admin, an Artisan command, a seeder, a
     * tinker session. A rule that only the admin enforces is a rule that stops
     * existing the moment content arrives another way.
     *
     * Runs before rendering, so a rejected save does not pay to parse a
     * document it is about to throw away.
     *
     * Warnings are collected and left on the entry for the admin to show. They
     * do not stop anything.
     */
    private function lint(Entry $entry): void
    {
        $issues = $this->linter->lint($entry);

        $entry->lintWarnings = array_values(array_filter(
            $issues,
            fn (LintIssue $issue): bool => ! $issue->blocking(),
        ));

        $blocking = $this->linter->blocking($issues);

        if ($blocking === []) {
            return;
        }

        $messages = [];

        foreach ($blocking as $issue) {
            $messages[$issue->field][] = $issue->describe();
        }

        throw ValidationException::withMessages($messages);
    }

    /**
     * Record the slug, and create a 301 from any slug it replaces.
     *
     * Automatic and not optional. A human renaming a slug will not remember to
     * add the redirect, and the consequence (a 404 on a URL Google has indexed
     * and other sites link to) is invisible until traffic drops weeks later.
     *
     * Only fires for entries that have been published, because an unpublished
     * draft's old slug was never a live URL and does not need preserving.
     */
    private function recordSlug(Entry $entry): void
    {
        if ($entry->routeless()) {
            return;
        }

        $previous = $entry->getOriginal('slug');
        $changed = $previous !== null && $previous !== $entry->slug;
        $wasPublished = $entry->getOriginal('status') === 'published';

        DB::table('entry_slugs')->insertOrIgnore([
            'entry_id' => $entry->id,
            'collection' => $entry->collection,
            'slug' => $entry->slug,
            'created_at' => now(),
        ]);

        if (! $changed || ! $wasPublished) {
            return;
        }

        $from = (string) $entry->schema()->urlFor((string) $previous);
        $to = $entry->url();

        if ($from === $to) {
            return;
        }

        // A rename can happen more than once, so any existing redirect that
        // pointed at the old URL is re-pointed at the new one. Without this you
        // accumulate chains: /a to /b, /b to /c.
        Redirect::query()->where('to', $from)->update(['to' => $to]);

        Redirect::query()->updateOrCreate(
            ['from' => $from],
            [
                'to' => $to,
                'status' => 301,
                'match_type' => 'exact',
                'entry_id' => $entry->id,
                'notes' => 'Created automatically on slug change.',
            ],
        );
    }

    /**
     * Snapshot the entry as it now stands.
     *
     * Stores authored values, not rendered HTML: the HTML is derived and would
     * double the row for nothing, since restoring re-renders anyway.
     */
    private function snapshot(Entry $entry): void
    {
        EntryRevision::query()->create([
            'entry_id' => $entry->id,
            'snapshot' => [
                'title' => $entry->title,
                'slug' => $entry->slug,
                'status' => $entry->status,
                'published_at' => $entry->published_at?->toIso8601String(),
                'sort_order' => $entry->sort_order,
                'data' => $entry->data,
                'seo' => $entry->seo,
            ],
            'created_by' => $entry->updated_by,
            'created_at' => now(),
        ]);
    }

    private function fillSlug(Entry $entry): void
    {
        if (filled($entry->slug)) {
            return;
        }

        $field = $entry->schema()->field('slug');
        $source = $field?->slugSource() ?? 'title';

        $value = $source === 'title'
            ? $entry->title
            : (string) $entry->value($source, '');

        $entry->slug = Str::slug($value);
    }

    /**
     * Pre-render every rich text field into `rendered`, at any depth.
     *
     * This is the fix for Kritano issue 3d, generalised. The old CMS
     * pre-rendered top-level rich text but not rich text inside a block, so
     * the Astro theme carried a second, hand-written renderer for the nested
     * case and the two produced different output. Phase 1 fixed the two known
     * depths by hand: a loop for top-level fields and a loop for fields one
     * level inside a block.
     *
     * Repeaters make that unworkable. Rich text can now sit two levels down
     * (a repeater row) or three (a repeater inside a block), and adding a loop
     * per depth would mean the next nesting anyone invents silently renders
     * nothing, which is exactly the bug this was supposed to have closed.
     *
     * So it walks the schema instead. Rendered HTML is stored at the same path
     * the source sits at, which keeps `data_get($entry->rendered, $path)`
     * working for every depth and preserves the shape Phase 1 already wrote.
     */
    private function renderRichText(Entry $entry): void
    {
        $rendered = $entry->rendered ?? [];

        $before = $rendered;

        $this->renderFields(
            $entry->schema()->allFields(),
            (array) $entry->data,
            $rendered,
            prefix: '',
        );

        if ($rendered !== $before) {
            $entry->rendered = $rendered;
        }
    }

    /**
     * Render the rich text in one level of fields, recursing into containers.
     *
     * @param  array<int, Field>  $fields
     * @param  array<string, mixed>  $data  Authored values at this level
     * @param  array<string, mixed>  $rendered  Written by reference, mirroring the data path
     */
    private function renderFields(array $fields, array $data, array &$rendered, string $prefix): void
    {
        foreach ($fields as $field) {
            $value = $data[$field->name] ?? null;
            $path = $prefix === '' ? $field->name : $prefix.'.'.$field->name;

            if ($field->type === 'richText') {
                $html = $this->renderer->render($value);

                // Only write when it differs, so an entry re-saved to flip its
                // status does not mark `rendered` dirty and re-write the row.
                if (data_get($rendered, $path) !== $html) {
                    data_set($rendered, $path, $html);
                }

                continue;
            }

            if ($field->type === 'repeater' && is_array($value)) {
                foreach ($value as $index => $row) {
                    if (is_array($row)) {
                        $this->renderFields($field->subFields(), $row, $rendered, $path.'.'.$index);
                    }
                }

                continue;
            }

            if ($field->type === 'group' && is_array($value)) {
                $this->renderFields($field->subFields(), $value, $rendered, $path);

                continue;
            }

            if ($field->type === 'blocks' && is_array($value)) {
                $this->renderBlocks($value, $rendered, $path);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @param  array<string, mixed>  $rendered
     */
    private function renderBlocks(array $blocks, array &$rendered, string $prefix): void
    {
        foreach ($blocks as $index => $block) {
            if (! is_array($block)) {
                continue;
            }

            $handle = $block['type'] ?? $block['_type'] ?? null;

            if (! is_string($handle)) {
                continue;
            }

            $definition = $this->blocks->find($handle);

            if ($definition === null) {
                continue;
            }

            // Both storage shapes, as BlockRenderer normalises them: the
            // legacy CMS wrote flat and nested at different times.
            $data = is_array($block['data'] ?? null)
                ? $block['data']
                : array_diff_key($block, ['type' => true, '_type' => true]);

            $this->renderFields($definition->allFields(), $data, $rendered, $prefix.'.'.$index);
        }
    }
}
