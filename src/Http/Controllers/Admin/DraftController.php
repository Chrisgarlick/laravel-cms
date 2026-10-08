<?php

declare(strict_types=1);

namespace Cg\Cms\Http\Controllers\Admin;

use Cg\Cms\Admin\EntryPayload;
use Cg\Cms\Content\EntryObserver;
use Cg\Cms\Lint\Linter;
use Cg\Cms\Lint\LintIssue;
use Cg\Cms\Models\Entry;
use Cg\Cms\Models\EntryRevision;
use Cg\Cms\Schema\Collection as CollectionSchema;
use Cg\Cms\Schema\CollectionRegistry;
use Cg\Cms\Seo\Checks\EntrySeoChecks;
use Cg\Cms\Seo\SeoResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Autosave, and the live lint and SEO panel.
 *
 * Both work on an entry that has not been saved, which is what makes them
 * useful: an editor wants to know their title is four characters too long
 * before they publish, not after.
 *
 * These return JSON rather than an Inertia response on purpose. An Inertia
 * response replaces the page's props, and doing that every fifteen seconds
 * would fight the form state the editor is currently typing into.
 */
final class DraftController
{
    /** Marks a revision as machine-made, using the column already there. */
    public const AUTOSAVE_LABEL = 'autosave';

    public function __construct(
        private readonly CollectionRegistry $collections,
        private readonly EntryPayload $payload,
        private readonly Linter $linter,
        private readonly EntrySeoChecks $seoChecks,
    ) {}

    /**
     * Store the editor's work in progress as a revision.
     *
     * Deliberately not a save to the entry. Autosaving over the live row would
     * publish half-finished edits on a published page every fifteen seconds,
     * and there is no undo for that. A revision is recoverable, invisible to
     * the public site, and exactly what section 5.12 asks for.
     *
     * Only one autosave revision is kept per entry. Fifteen-second intervals
     * over an afternoon's writing would otherwise bury the manual saves that
     * someone actually wants to find in the history.
     */
    public function store(Request $request, string $collection, Entry $entry): JsonResponse
    {
        $schema = $this->schema($collection);
        $this->assertBelongsTo($entry, $schema);

        $snapshot = $this->snapshot($schema, (array) $request->input('values', []));
        $now = now();

        // upsert, not updateOrCreate. Two tabs open on the same entry is an
        // ordinary thing for one person to do, and updateOrCreate is a select
        // followed by an insert that loses that race. The unique index on
        // (entry_id, label) is what lets this be one atomic statement.
        EntryRevision::query()->upsert(
            [[
                'entry_id' => $entry->id,
                'label' => self::AUTOSAVE_LABEL,
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'created_by' => $request->user()?->getAuthIdentifier(),
                'created_at' => $now,
            ]],
            uniqueBy: ['entry_id', 'label'],
            update: ['snapshot', 'created_by', 'created_at'],
        );

        return response()->json(['savedAt' => $now->toIso8601String()]);
    }

    /**
     * Lint and SEO for values that have not been saved.
     *
     * Builds an unsaved Entry and runs the real linter and the real SEO
     * resolver over it. Reimplementing either in TypeScript would mean two
     * definitions of the brand voice, and the one in the browser would be the
     * one that drifted.
     */
    public function preview(Request $request, string $collection): JsonResponse
    {
        $schema = $this->schema($collection);

        $entry = $this->unsavedEntry($request, $schema);

        // A slug is needed to resolve a URL, and a brand new entry has not got
        // one yet. Anything will do: the preview shows shape, not the final
        // address.
        if (! filled($entry->slug)) {
            $entry->slug = 'untitled';
        }

        // The SEO checks read headings and links out of rendered HTML, and
        // nothing has rendered what is in the form yet.
        app(EntryObserver::class)->prerender($entry);

        return response()->json([
            'lint' => array_map(
                fn (LintIssue $issue): array => $issue->toArray(),
                $this->linter->lint($entry),
            ),
            'seo' => $entry->routeless()
                ? null
                : app(SeoResolver::class)->forEntry($entry)->toArray(),
            'seoChecks' => array_map(
                fn (LintIssue $issue): array => $issue->toArray(),
                $this->seoChecks->check($entry),
            ),
        ]);
    }

    /**
     * The entry as the form currently describes it, never saved.
     *
     * For an existing entry, the stored row with the form's values laid over
     * it, exactly as an update would. Starting from a blank model instead
     * would make the duplicate check find the entry being edited and report
     * it as its own duplicate, and would lose everything the form does not
     * post.
     */
    private function unsavedEntry(Request $request, CollectionSchema $schema): Entry
    {
        $attributes = $this->payload->toAttributes($schema, (array) $request->input('values', []));
        $id = $request->integer('entry');

        $existing = $id > 0
            ? Entry::query()->collection($schema->handle)->find($id)
            : null;

        if ($existing !== null) {
            $existing->fill($this->payload->merged($existing, $attributes));

            return $existing;
        }

        $entry = new Entry(['collection' => $schema->handle]);
        $entry->fill($attributes);

        return $entry;
    }

    /**
     * Shape a revision snapshot the same way the observer does.
     *
     * It has to match, because restoring a revision fills these keys straight
     * onto the model. A snapshot with a different shape would restore an entry
     * into a state it was never in.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function snapshot(CollectionSchema $schema, array $values): array
    {
        $attributes = $this->payload->toAttributes($schema, $values);

        return [
            'title' => $attributes['title'] ?? '',
            'slug' => $attributes['slug'] ?? '',
            'status' => $attributes['status'] ?? 'draft',
            'published_at' => $attributes['published_at'] ?? null,
            'sort_order' => $attributes['sort_order'] ?? null,
            'data' => $attributes['data'] ?? [],
            'seo' => $attributes['seo'] ?? [],
        ];
    }

    private function schema(string $collection): CollectionSchema
    {
        if (! $this->collections->has($collection)) {
            throw new NotFoundHttpException("Unknown collection [{$collection}].");
        }

        return $this->collections->get($collection);
    }

    private function assertBelongsTo(Entry $entry, CollectionSchema $schema): void
    {
        if ($entry->collection !== $schema->handle) {
            throw new NotFoundHttpException;
        }
    }
}
