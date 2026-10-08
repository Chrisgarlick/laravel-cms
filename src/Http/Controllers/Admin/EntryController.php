<?php

declare(strict_types=1);

namespace Cg\Cms\Http\Controllers\Admin;

use Cg\Cms\Admin\EntryPayload;
use Cg\Cms\Admin\EntryQuery;
use Cg\Cms\Admin\SchemaSerializer;
use Cg\Cms\Content\ContentService;
use Cg\Cms\Lint\Linter;
use Cg\Cms\Lint\LintIssue;
use Cg\Cms\Models\Entry;
use Cg\Cms\Models\EntryRevision;
use Cg\Cms\Schema\Collection as CollectionSchema;
use Cg\Cms\Schema\CollectionRegistry;
use Cg\Cms\Seo\Checks\EntrySeoChecks;
use Cg\Cms\Seo\SeoResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Create, edit and delete entries in any collection.
 *
 * There is one of these, not one per collection, because everything that
 * differs between collections is already declared in the schema. Adding a
 * collection is a change to `app/Cms/schema.php` and nothing else, which is
 * the property the whole design exists to protect.
 *
 * The listing here is deliberately crude: a table with no search, no filter
 * and no pagination. Section 12 puts the real collection index in step 4, and
 * building it now would mean building it twice. What it needs to do today is
 * get you to an entry.
 */
final class EntryController
{
    public function __construct(
        private readonly CollectionRegistry $collections,
        private readonly SchemaSerializer $serializer,
        private readonly EntryPayload $payload,
        private readonly Linter $linter,
        private readonly EntryQuery $query,
        private readonly EntrySeoChecks $seoChecks,
    ) {}

    public function index(Request $request, string $collection): Response
    {
        $schema = $this->schema($collection);

        return Inertia::render('Entries/Index', [
            /*
             * Closures, not values.
             *
             * A filter change reloads with `only: ['entries']`, and Inertia
             * skips evaluating any prop it is not returning. Serialising the
             * collection schema means a query per relation field to build its
             * choices, so as a plain value that work would happen on every
             * keystroke of the search box and then be thrown away.
             *
             * Not Inertia::optional(), which omits a prop from the first load
             * entirely and would render an empty table until something asked
             * for it.
             */
            'collection' => fn (): array => $this->serializer->forEditor($schema),
            'sortable' => EntryQuery::SORTABLE,
            'filters' => fn (): array => $this->query->filters($request, $schema),
            'entries' => fn (): array => $this->paginated($this->query->run($request, $schema)),
        ]);
    }

    /**
     * Publish, unpublish or delete several entries at once.
     *
     * Each one goes through the model rather than a mass update, so the
     * observer still renders, lints, records slug history and purges the
     * cache. A bulk publish that skipped all of that would leave the site
     * serving stale pages for content that is now live, which is the specific
     * failure the whole cache design exists to prevent.
     *
     * Slower, obviously. Correct is worth more than fast on an action somebody
     * takes a few times a week.
     */
    public function bulk(Request $request, string $collection): RedirectResponse
    {
        $schema = $this->schema($collection);

        $validated = $request->validate([
            'action' => ['required', 'in:publish,unpublish,delete'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $entries = Entry::query()
            ->collection($schema->handle)
            ->whereIn('id', $validated['ids'])
            ->get();

        $failed = [];

        foreach ($entries as $entry) {
            try {
                match ($validated['action']) {
                    'publish' => $entry->update(['status' => 'published']),
                    'unpublish' => $entry->update(['status' => 'draft']),
                    'delete' => $entry->delete(),
                };
            } catch (ValidationException $e) {
                // A blocking lint rule. One entry failing must not abandon the
                // rest, and it must not be silent either: publishing nine of
                // ten and reporting success is worse than reporting both.
                $failed[] = $entry->title;
            }
        }

        $done = $entries->count() - count($failed);

        if ($failed !== []) {
            return back()->with('error', sprintf(
                '%d of %d done. Blocked by content rules: %s',
                $done,
                $entries->count(),
                implode(', ', array_slice($failed, 0, 3)).(count($failed) > 3 ? ' and more' : ''),
            ));
        }

        return back()->with('success', sprintf('%d %s.', $done, $validated['action'].'ed'));
    }

    /**
     * The paginator, as the table needs it.
     *
     * @param  LengthAwarePaginator<Entry>  $entries
     * @return array<string, mixed>
     */
    private function paginated(LengthAwarePaginator $entries): array
    {
        return [
            'data' => $entries->getCollection()->map(fn (Entry $entry): array => [
                'id' => $entry->id,
                'title' => $entry->title,
                'slug' => $entry->slug,
                'status' => $this->statusOf($entry),
                'url' => $entry->routeless() ? null : $entry->url(),
                'publishedAt' => $entry->published_at?->toIso8601String(),
                'updatedAt' => $entry->updated_at?->toIso8601String(),
            ])->all(),
            'total' => $entries->total(),
            'perPage' => $entries->perPage(),
            'currentPage' => $entries->currentPage(),
            'lastPage' => $entries->lastPage(),
        ];
    }

    /**
     * Published, scheduled or draft.
     *
     * `status` alone does not distinguish an entry that is published from one
     * that is published with a future date, and those mean different things to
     * somebody scanning a list for what is live.
     */
    private function statusOf(Entry $entry): string
    {
        if ($entry->status !== 'published') {
            return $entry->status;
        }

        return $entry->published_at !== null && $entry->published_at->isFuture()
            ? 'scheduled'
            : 'published';
    }

    public function create(string $collection): Response
    {
        $schema = $this->schema($collection);

        return Inertia::render('Entries/Edit', [
            'collection' => $this->serializer->forEditor($schema),
            'entry' => null,
            'values' => $this->payload->blank($schema),
            'revisions' => [],
            'draft' => null,
            'lint' => [],
            'seoPreview' => null,
            'seoChecks' => [],
        ]);
    }

    public function edit(string $collection, Entry $entry): Response
    {
        $schema = $this->schema($collection);
        $this->assertBelongsTo($entry, $schema);

        return Inertia::render('Entries/Edit', [
            'collection' => $this->serializer->forEditor($schema),
            'entry' => [
                'id' => $entry->id,
                'status' => $entry->status,
                'url' => $entry->routeless() ? null : $entry->url(),
                'updatedAt' => $entry->updated_at?->toIso8601String(),
            ],
            'values' => $this->payload->toForm($entry, $schema),
            'revisions' => $this->revisions($entry),
            'draft' => $this->recoverableDraft($entry),
            'lint' => $this->lint($entry),
            'seoPreview' => $this->seoPreview($entry),
            'seoChecks' => array_map(
                fn (LintIssue $issue): array => $issue->toArray(),
                $this->seoChecks->check($entry),
            ),
        ]);
    }

    public function store(Request $request, string $collection): RedirectResponse
    {
        $schema = $this->schema($collection);

        $entry = new Entry(['collection' => $schema->handle]);
        $entry->fill($this->payload->toAttributes($schema, $this->validated($request, $schema)));

        $entry->save();

        return redirect()
            ->route('cgcms.admin.entries.edit', [$schema->handle, $entry->id])
            ->with('success', 'Created.');
    }

    public function update(Request $request, string $collection, Entry $entry): RedirectResponse
    {
        $schema = $this->schema($collection);
        $this->assertBelongsTo($entry, $schema);

        $attributes = $this->payload->toAttributes($schema, $this->validated($request, $schema));

        $entry->fill($this->payload->merged($entry, $attributes));

        $entry->save();

        // The editor applied a CLI proposal into the form and this save is
        // the decision, so the proposal is done with.
        if ($request->boolean('_proposal_applied')) {
            $entry->revisions()->where('label', ContentService::PROPOSAL_LABEL)->delete();
        }

        return back()->with('success', 'Saved.');
    }

    public function destroy(string $collection, Entry $entry): RedirectResponse
    {
        $schema = $this->schema($collection);
        $this->assertBelongsTo($entry, $schema);

        // Soft delete. The observer purges the cache, so the page stops being
        // served immediately, and the row is still there when somebody asks
        // for it back an hour later.
        $entry->delete();

        return redirect()
            ->route('cgcms.admin.entries.index', $schema->handle)
            ->with('success', 'Deleted.');
    }

    /**
     * Restore a revision onto its entry.
     *
     * Goes through the model, so rich text re-renders and the cache purges. A
     * restore is a content change like any other, and treating it as a row
     * update is how the old CMS ended up serving stale HTML after one.
     */
    public function restore(string $collection, Entry $entry, EntryRevision $revision): RedirectResponse
    {
        $schema = $this->schema($collection);
        $this->assertBelongsTo($entry, $schema);

        if ($revision->entry_id !== $entry->id) {
            throw new NotFoundHttpException;
        }

        $revision->restore();

        return back()->with('success', 'Restored.');
    }

    /**
     * Validate against the schema's own rules.
     *
     * Note what is *not* here: any handling of the brand voice rules. The
     * linter throws from the entry observer, which is what makes it hold for a
     * seeder and a tinker session as well as for this form, and what it throws
     * is already a ValidationException keyed by field name. Inertia turns that
     * into `errors` on the page with no translation at this layer.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, CollectionSchema $schema): array
    {
        return $request->validate([
            ...$schema->validationRules(),
            'seo' => ['sometimes', 'array'],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function revisions(Entry $entry): array
    {
        return $entry->revisions()
            // Autosaves are machine-made and there is only ever one. Mixing it
            // into the history would put a row that changes every fifteen
            // seconds at the top of the list somebody is scanning for the
            // version they wrote on Tuesday.
            ->whereNull('label')
            ->limit(20)
            ->get()
            ->map(fn (EntryRevision $revision): array => [
                'id' => $revision->id,
                'createdAt' => $revision->created_at?->toIso8601String(),
                'title' => (string) data_get($revision->snapshot, 'title', ''),
                'status' => (string) data_get($revision->snapshot, 'status', ''),
            ])
            ->all();
    }

    /**
     * An autosave newer than the entry itself.
     *
     * Means the browser was closed, or crashed, with unsaved work in it. The
     * editor offers to restore it rather than restoring silently: overwriting
     * what somebody deliberately reverted would be worse than losing an
     * autosave they had already given up on.
     *
     * @return array<string, mixed>|null
     */
    private function recoverableDraft(Entry $entry): ?array
    {
        // A proposal from the CLI (Claude Code) wins over an autosave: it is
        // a deliberate change waiting for a decision, not a crash recovery.
        $draft = $entry->revisions()->where('label', ContentService::PROPOSAL_LABEL)->first()
            ?? $entry->revisions()->where('label', DraftController::AUTOSAVE_LABEL)->first();

        if ($draft === null || $entry->updated_at === null) {
            return null;
        }

        // An autosave older than the last save is stale. A proposal is not:
        // it waits until somebody applies or discards it.
        $stale = $draft->created_at === null || $draft->created_at->lessThanOrEqualTo($entry->updated_at);

        if ($stale && $draft->label !== ContentService::PROPOSAL_LABEL) {
            return null;
        }

        return [
            'id' => $draft->id,
            'savedAt' => $draft->created_at->toIso8601String(),
            'values' => $draft->snapshot,
            'source' => $draft->label === ContentService::PROPOSAL_LABEL ? 'proposal' : 'autosave',
            'by' => $draft->created_by === null ? null : DB::table('users')->where('id', $draft->created_by)->value('name'),
        ];
    }

    /**
     * Throw away a waiting proposal or autosave without applying it.
     */
    public function discardDraft(string $collection, Entry $entry): RedirectResponse
    {
        $schema = $this->schema($collection);
        abort_unless($entry->collection === $schema->handle, 404);

        $entry->revisions()->whereIn('label', [ContentService::PROPOSAL_LABEL, DraftController::AUTOSAVE_LABEL])->delete();

        return back()->with('success', 'Discarded.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lint(Entry $entry): array
    {
        return array_map(
            fn (LintIssue $issue): array => $issue->toArray(),
            $this->linter->lint($entry),
        );
    }

    /**
     * What the head of this entry's page would say.
     *
     * Rendered by the same resolver the front end uses, so the panel shows
     * what will actually be emitted rather than a reimplementation of the
     * fallback chain that drifts from it.
     *
     * @return array<string, mixed>|null
     */
    private function seoPreview(Entry $entry): ?array
    {
        if ($entry->routeless()) {
            return null;
        }

        return app(SeoResolver::class)->forEntry($entry)->toArray();
    }

    private function schema(string $collection): CollectionSchema
    {
        if (! $this->collections->has($collection)) {
            throw new NotFoundHttpException("Unknown collection [{$collection}].");
        }

        return $this->collections->get($collection);
    }

    /**
     * Refuse an entry reached through the wrong collection's URL.
     *
     * Route model binding resolves an entry by id alone, so
     * /admin/article/14/edit would happily open a project. Beyond being
     * confusing, it would render that project through the article schema and
     * save it back with article fields.
     */
    private function assertBelongsTo(Entry $entry, CollectionSchema $schema): void
    {
        if ($entry->collection !== $schema->handle) {
            throw new NotFoundHttpException;
        }
    }
}
