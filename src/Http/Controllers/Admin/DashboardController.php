<?php

declare(strict_types=1);

namespace Cg\Cms\Http\Controllers\Admin;

use Cg\Cms\Models\Entry;
use Cg\Cms\Models\FormSubmission;
use Cg\Cms\Models\Media;
use Cg\Cms\Models\NotFoundEntry;
use Cg\Cms\Models\PageCacheEntry;
use Cg\Cms\Models\SeoIssue;
use Cg\Cms\Schema\Collection as CollectionSchema;
use Cg\Cms\Schema\CollectionRegistry;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin landing screen: what needs attention, in one place.
 *
 * Section 5.12's list: recent edits, SEO issues, 404s worth redirecting, form
 * submissions and the cache. Each panel answers "is there something to do?"
 * and links to the screen where it gets done; none of them is a second copy
 * of that screen. A dashboard that duplicates its screens is one that falls
 * out of step with them.
 *
 * Every panel is a closure, deferred to after the first paint. Seven panels
 * means seven queries, one of them over the page cache table, and the
 * collection counts at the top are what somebody opening the admin usually
 * wants first.
 */
final class DashboardController
{
    public function __invoke(CollectionRegistry $collections): Response
    {
        return Inertia::render('Dashboard', [
            'collections' => $this->counts($collections),
            'recent' => Inertia::defer(fn (): array => $this->recentEdits($collections)),
            'seo' => Inertia::defer(fn (): array => $this->seo()),
            'notFound' => Inertia::defer(fn (): array => $this->notFound()),
            'submissions' => Inertia::defer(fn (): array => $this->submissions()),
            'media' => Inertia::defer(fn (): array => $this->media()),
            'cache' => Inertia::defer(fn (): array => $this->cacheStats($collections)),
        ]);
    }

    /**
     * Published and draft counts per collection.
     *
     * One grouped query rather than two per collection. There are nine
     * collections today, so eighteen queries would be unnoticeable and would
     * also be the kind of thing that is unnoticeable right up until the
     * dashboard is the slowest page in the admin.
     *
     * @return array<int, array<string, mixed>>
     */
    private function counts(CollectionRegistry $collections): array
    {
        $rows = Entry::query()
            ->select('collection', 'status', DB::raw('count(*) as total'))
            ->groupBy('collection', 'status')
            ->get();

        return array_values(array_map(
            fn (CollectionSchema $schema): array => [
                'handle' => $schema->handle,
                'label' => $schema->labelText(),
                'published' => (int) $rows
                    ->where('collection', $schema->handle)
                    ->where('status', 'published')
                    ->sum('total'),
                'drafts' => (int) $rows
                    ->where('collection', $schema->handle)
                    ->where('status', '!=', 'published')
                    ->sum('total'),
            ],
            $collections->all(),
        ));
    }

    /**
     * The last few entries anybody changed, and who changed them.
     *
     * The thing most often wanted on opening the admin is "where was I", and
     * this answers it without a search.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentEdits(CollectionRegistry $collections): array
    {
        return Entry::query()
            ->leftJoin('users', 'users.id', '=', 'entries.updated_by')
            ->whereIn('entries.collection', $collections->handles())
            ->orderByDesc('entries.updated_at')
            ->orderByDesc('entries.id')
            ->limit(8)
            ->get(['entries.id', 'entries.collection', 'entries.title', 'entries.status', 'entries.updated_at', 'users.name as editor'])
            ->map(fn (Entry $entry): array => [
                'id' => $entry->id,
                'collection' => $entry->collection,
                'label' => $collections->get($entry->collection)->labelText(),
                'title' => $entry->title,
                'status' => $entry->status,
                'updatedAt' => $entry->updated_at?->toIso8601String(),
                'editor' => $entry->getAttribute('editor'),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function seo(): array
    {
        $bySeverity = SeoIssue::query()
            ->selectRaw('severity, count(*) as total')
            ->groupBy('severity')
            ->pluck('total', 'severity');

        return [
            'blocking' => (int) ($bySeverity['block'] ?? 0),
            'warnings' => (int) ($bySeverity['warn'] ?? 0),

            // Null means never run, which the panel says rather than showing
            // a reassuring zero for a check that has not happened.
            'auditedAt' => SeoIssue::query()->max('found_at')
                ?? (DB::table('link_graph')->max('found_at')),

            'topRules' => SeoIssue::query()
                ->selectRaw('rule, count(*) as total')
                ->groupBy('rule')
                ->orderByDesc('total')
                ->limit(4)
                ->get()
                ->map(fn ($row): array => ['rule' => $row->rule, 'total' => (int) $row->total])
                ->all(),
        ];
    }

    /**
     * The 404s most worth turning into redirects: the most hit, recently.
     *
     * Ordered by hits within the last month, because a URL that was hit four
     * hundred times last year and never since is history, while one hit
     * twelve times this week is a link somebody is clicking right now.
     *
     * @return array<int, array<string, mixed>>
     */
    private function notFound(): array
    {
        return NotFoundEntry::query()
            ->where('last_seen_at', '>=', now()->subDays(30))
            ->orderByDesc('hits')
            ->orderBy('path')
            ->limit(5)
            ->get()
            ->map(fn (NotFoundEntry $miss): array => [
                'id' => $miss->id,
                'path' => $miss->path,
                'hits' => $miss->hits,
                'lastSeenAt' => $miss->last_seen_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function submissions(): array
    {
        $since = now()->subDays(7);

        $counts = FormSubmission::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('count(*) filter (where rejected_for is null) as accepted')
            ->selectRaw('count(*) filter (where rejected_for is not null) as rejected')
            ->first();

        return [
            'accepted' => (int) ($counts->accepted ?? 0),
            'rejected' => (int) ($counts->rejected ?? 0),

            // Accepted but never emailed: a lead sitting in the database that
            // nobody knows about. The one number here that is always urgent.
            'unnotified' => FormSubmission::query()
                ->whereNull('rejected_for')
                ->whereNull('notified_at')
                ->where('created_at', '<', now()->subMinutes(10))
                ->count(),

            'latest' => FormSubmission::query()
                ->whereNull('rejected_for')
                ->latest()
                ->limit(5)
                ->get(['id', 'form', 'context', 'created_at'])
                ->map(fn (FormSubmission $submission): array => [
                    'id' => $submission->id,
                    'form' => $submission->form,
                    'context' => $submission->context,
                    'createdAt' => $submission->created_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /** @return array{total: int, missingAlt: int} */
    private function media(): array
    {
        return [
            'total' => Media::query()->count(),
            'missingAlt' => Media::query()->where(fn ($q) => $q->whereNull('alt')->orWhere('alt', ''))->count(),
        ];
    }

    /**
     * What is on disk, against what could be.
     *
     * Hit rates live in nginx's access log, which PHP never sees: a cache hit
     * is precisely a request that did not reach it. What the CMS can say is
     * how much of the site is warm, which is the number that predicts whether
     * the next visitor gets a hit.
     *
     * @return array{pages: int, bytes: int, publicPages: int}
     */
    private function cacheStats(CollectionRegistry $collections): array
    {
        $routable = array_keys($collections->routable());

        $indexes = count(array_filter(
            $collections->routable(),
            fn (CollectionSchema $schema): bool => $schema->indexPath() !== null,
        ));

        return [
            'pages' => PageCacheEntry::query()->count(),
            'bytes' => (int) PageCacheEntry::query()->sum('bytes'),
            'publicPages' => $indexes + Entry::query()->whereIn('collection', $routable)->published()->count(),
        ];
    }
}
