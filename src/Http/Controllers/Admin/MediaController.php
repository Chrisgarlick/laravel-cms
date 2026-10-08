<?php

declare(strict_types=1);

namespace Cg\Cms\Http\Controllers\Admin;

use Cg\Cms\Cache\CachePurger;
use Cg\Cms\Media\MediaLibrary;
use Cg\Cms\Models\Entry;
use Cg\Cms\Models\Media;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The media library: a grid, uploads, alt text, focal point and usage.
 *
 * Uploads and the picker's listing are JSON rather than Inertia. An upload is
 * one request per file so each can report its own progress, and an Inertia
 * response to each would replace the page's props as many times as there are
 * files, mid-drag. The picker is a modal inside the entry editor, and an
 * Inertia visit from there would navigate away from the form being edited.
 */
final class MediaController
{
    private const PER_PAGE = 48;

    public function __construct(private readonly MediaLibrary $library) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        return Inertia::render('Media/Index', [
            'filters' => $filters,
            'media' => fn (): array => $this->paginated($this->query($filters)),

            // How many images each smart filter holds. No folders: an image
            // belongs to many pages, so the useful questions are "what is
            // unused", "what lacks alt text" and "what came in recently".
            'counts' => fn (): array => [
                'all' => Media::query()->count(),
                'unused' => $this->unused(Media::query())->count(),
                'missing-alt' => Media::query()->where(fn (Builder $q) => $q->whereNull('alt')->orWhere('alt', ''))->count(),
                'recent' => Media::query()->where('created_at', '>=', now()->subDays(30))->count(),
            ],

            // The detail panel, for whichever image is selected. A closure, so
            // paging through the grid does not pay for a usage scan of an
            // image nobody has opened.
            'selected' => fn (): ?array => $this->selected($request),

            'limits' => [
                'maxKb' => (int) config('cg-cms.media.max_upload_kb'),
                'accept' => (array) config('cg-cms.media.mimes'),
            ],
        ]);
    }

    /**
     * The picker's listing. Same query, same shape, as JSON.
     *
     * Also answers `?ids[]=` with exactly those images, which is how a media
     * field shows a thumbnail for the reference it already holds: the entry
     * stores a uuid, not a URL, and only the library knows which fallback
     * format that image's thumbnail is in.
     */
    public function browse(Request $request): JsonResponse
    {
        $ids = array_values(array_filter((array) $request->query('ids', []), 'is_string'));

        if ($ids !== []) {
            return response()->json([
                'data' => Media::query()
                    ->whereIn('uuid', array_slice($ids, 0, 100))
                    ->get()
                    ->map(fn (Media $media): array => $this->item($media))
                    ->all(),
            ]);
        }

        return response()->json($this->paginated($this->query($this->filters($request)), withUsage: false));
    }

    /**
     * One file. The client sends several of these in sequence.
     *
     * Returns 200 rather than 201 when the file was already in the library, so
     * the uploader can say "already had this" instead of implying a copy was
     * made.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            // Size checked here; type checked by the library from the file's
            // own header, because the browser's claim about it is a claim.
            'file' => ['required', 'file', 'max:'.(int) config('cg-cms.media.max_upload_kb')],
        ]);

        $media = $this->library->store(
            $request->file('file'),
            $request->user()?->getAuthIdentifier(),
        );

        return response()->json(
            $this->item($media),
            $media->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function update(Request $request, Media $media): RedirectResponse
    {
        $validated = $request->validate([
            'alt' => ['nullable', 'string', 'max:255'],
            'focal_x' => ['sometimes', 'numeric', 'between:0,1'],
            'focal_y' => ['sometimes', 'numeric', 'between:0,1'],
        ]);

        // Only when the key was sent. Setting a focal point posts coordinates
        // alone, and reading an absent alt as null would wipe the alt text
        // every time somebody clicked the image.
        $alt = array_key_exists('alt', $validated)
            ? (trim((string) $validated['alt']) ?: null)
            : $media->alt;

        if ($alt !== $media->alt) {
            $media->forceFill(['alt' => $alt])->save();

            // Pages that rely on the library's alt are now wrong on disk.
            // Purging them is the same cache contract every content change
            // honours: nothing edited in the admin is left stale in public.
            $this->purgePagesUsing($media);
        }

        if (isset($validated['focal_x'], $validated['focal_y'])) {
            $x = (float) $validated['focal_x'];
            $y = (float) $validated['focal_y'];

            if (abs($x - $media->focal_x) > 0.001 || abs($y - $media->focal_y) > 0.001) {
                $this->library->refocus($media, $x, $y);
            }
        }

        return back()->with('success', 'Saved.');
    }

    /**
     * Delete, unless something still uses it.
     *
     * Refused rather than allowed with a warning. A deleted image in use
     * becomes a hole in a published page, and a confirm dialog is a thing
     * people click through.
     */
    public function destroy(Media $media): RedirectResponse
    {
        $usage = $this->library->usage($media);

        if ($usage !== []) {
            return back()->with('error', sprintf(
                'Still used by %s. Remove it there first.',
                implode(', ', array_map(fn (array $entry): string => $entry['title'], array_slice($usage, 0, 3)))
                    .(count($usage) > 3 ? ' and '.(count($usage) - 3).' more' : ''),
            ));
        }

        $this->library->delete($media);

        return redirect()
            ->route('cgcms.admin.media.index')
            ->with('success', 'Deleted '.$media->filename.'.');
    }

    private const FILTERS = ['all', 'unused', 'missing-alt', 'recent'];

    private const SORTS = [
        'newest' => ['created_at', 'desc'],
        'oldest' => ['created_at', 'asc'],
        'largest' => ['bytes', 'desc'],
        'name' => ['filename', 'asc'],
    ];

    /**
     * @return array{q: string, filter: string, sort: string, missingAlt: bool}
     */
    private function filters(Request $request): array
    {
        // `missingAlt=1` is the older spelling, kept so existing links work.
        $filter = $request->boolean('missingAlt') ? 'missing-alt' : (string) $request->query('filter', 'all');
        $filter = in_array($filter, self::FILTERS, true) ? $filter : 'all';
        $sort = (string) $request->query('sort', 'newest');

        return [
            'q' => trim((string) $request->query('q', '')),
            'filter' => $filter,
            'sort' => array_key_exists($sort, self::SORTS) ? $sort : 'newest',
            'missingAlt' => $filter === 'missing-alt',
        ];
    }

    /**
     * Images no live entry references, by the same text search the usage
     * counts use, so the filter and the "unused" badge cannot disagree.
     *
     * @param  Builder<Media>  $query
     * @return Builder<Media>
     */
    private function unused(Builder $query): Builder
    {
        return $query->whereNotExists(function ($sub): void {
            $sub->selectRaw('1')
                ->from('entries as e')
                ->whereNull('e.deleted_at')
                ->whereRaw("(e.data::text like '%' || media.uuid || '%' or e.seo::text like '%' || media.uuid || '%')");
        });
    }

    /**
     * @param  array{q: string, filter: string, sort: string, missingAlt: bool}  $filters
     * @return Builder<Media>
     */
    private function query(array $filters): Builder
    {
        $query = Media::query();

        if ($filters['q'] !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $filters['q']).'%';

            $query->where(function (Builder $q) use ($like): void {
                $q->where('filename', 'ilike', $like)->orWhere('alt', 'ilike', $like);
            });
        }

        match ($filters['filter']) {
            'missing-alt' => $query->where(fn (Builder $q) => $q->whereNull('alt')->orWhere('alt', '')),
            'unused' => $this->unused($query),
            'recent' => $query->where('created_at', '>=', now()->subDays(30)),
            default => null,
        };

        [$column, $direction] = self::SORTS[$filters['sort']];

        // The id as a tiebreak: a folder dragged in at once shares a
        // created_at, and without it a page boundary can show one image
        // twice and another never.
        return $query->orderBy($column, $direction)->orderBy('id', $direction);
    }

    /**
     * @param  Builder<Media>  $query
     * @return array<string, mixed>
     */
    private function paginated(Builder $query, bool $withUsage = true): array
    {
        /** @var LengthAwarePaginator<Media> $page */
        $page = $query->paginate(self::PER_PAGE)->withQueryString();

        $items = $page->getCollection();

        $usage = $withUsage
            ? $this->library->usageCounts($items->pluck('uuid')->all())
            : [];

        return [
            'data' => $items->map(fn (Media $media): array => [
                ...$this->item($media),
                'usage' => $usage[$media->uuid] ?? null,
            ])->all(),
            'total' => $page->total(),
            'perPage' => $page->perPage(),
            'currentPage' => $page->currentPage(),
            'lastPage' => $page->lastPage(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function selected(Request $request): ?array
    {
        $uuid = $request->query('selected');

        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        $media = Media::query()->where('uuid', $uuid)->first();

        if ($media === null) {
            return null;
        }

        return [
            ...$this->item($media),
            'usedBy' => $this->library->usage($media),
            'variants' => $media->variants()
                ->orderBy('preset')
                ->orderBy('format')
                ->get(['preset', 'format', 'width', 'height', 'bytes'])
                ->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(Media $media): array
    {
        return [
            'id' => $media->uuid,
            'filename' => $media->filename,
            'alt' => $media->alt,
            'mime' => $media->mime,
            'width' => $media->width,
            'height' => $media->height,
            'bytes' => $media->bytes,
            'focalX' => $media->focal_x,
            'focalY' => $media->focal_y,
            'thumb' => $media->url('thumb'),
            'preview' => $media->url('full'),
            'processed' => $media->processed_at !== null,
            'createdAt' => $media->created_at?->toIso8601String(),
        ];
    }

    private function purgePagesUsing(Media $media): void
    {
        $ids = array_column($this->library->usage($media), 'id');

        if ($ids === []) {
            return;
        }

        $purger = app(CachePurger::class);

        Entry::query()->whereIn('id', $ids)->each(fn (Entry $entry) => $purger->purge($entry));
    }
}
