<?php

declare(strict_types=1);

namespace Cg\Cms\Admin;

use Cg\Cms\Models\Entry;
use Cg\Cms\Schema\Collection as CollectionSchema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Turns the collection index's query string into a page of entries.
 *
 * Filtering on the server rather than in the browser, which is the whole
 * difference between this and the crude listing it replaces. Sending every
 * entry and filtering in React is fine at sixty articles and is exactly the
 * decision that becomes a rewrite at six thousand, because by then the table
 * has grown features that assume it holds everything.
 */
final class EntryQuery
{
    private const PER_PAGE = 25;

    /**
     * Columns that may be sorted on.
     *
     * A whitelist, because the sort column arrives in the query string and is
     * interpolated into an ORDER BY. Passing user input to orderBy() is the
     * one place in an otherwise parameterised query builder where a string
     * reaches SQL unescaped.
     *
     * @var array<int, string>
     */
    public const SORTABLE = ['title', 'slug', 'status', 'published_at', 'updated_at', 'sort_order'];

    /**
     * @return LengthAwarePaginator<Entry>
     */
    public function run(Request $request, CollectionSchema $schema): LengthAwarePaginator
    {
        $filters = $this->filters($request, $schema);

        $query = Entry::query()
            ->collection($schema->handle)
            ->select(['id', 'collection', 'title', 'slug', 'status', 'published_at', 'updated_at']);

        $this->applySearch($query, $filters['q']);
        $this->applyStatus($query, $filters['status']);

        $query->orderBy($filters['sort'], $filters['direction']);

        // A stable tiebreak. Without it, rows sharing a sort value can swap
        // between pages as Postgres is free to return them in any order, so
        // paging through a collection can show one entry twice and another
        // never.
        $query->orderBy('id', 'desc');

        return $query->paginate(self::PER_PAGE)->withQueryString();
    }

    /**
     * The filters in force, echoed back so the form can show them.
     *
     * Public because the controller sends it as its own prop: the table needs
     * to know what it is filtered by even on a partial reload that asks only
     * for the rows.
     *
     * @return array<string, mixed>
     */
    public function filters(Request $request, CollectionSchema $schema): array
    {
        $sort = (string) $request->query('sort', $schema->orderColumn());

        return [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', ''),
            'sort' => in_array($sort, self::SORTABLE, true) ? $sort : $schema->orderColumn(),
            'direction' => $request->query('direction') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * @param  Builder<Entry>  $query
     */
    private function applySearch(Builder $query, string $term): void
    {
        if ($term === '') {
            return;
        }

        // ILIKE with a leading wildcard cannot use a btree index, so this is a
        // sequential scan over the collection. Correct at this size and the
        // obvious thing to replace with a tsvector column once a collection
        // gets large enough to notice; the shape of the call site does not
        // change when that happens.
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $query->where(function (Builder $q) use ($like): void {
            $q->where('title', 'ilike', $like)->orWhere('slug', 'ilike', $like);
        });
    }

    /**
     * @param  Builder<Entry>  $query
     */
    private function applyStatus(Builder $query, string $status): void
    {
        match ($status) {
            'published' => $query->published(),

            // Not simply `status != published`: an entry with a future
            // publication date is scheduled rather than live, and an editor
            // looking for what is not yet public wants to see it here.
            'draft' => $query->where(function (Builder $q): void {
                $q->where('status', '!=', 'published')
                    ->orWhere('published_at', '>', now());
            }),

            default => null,
        };
    }
}
