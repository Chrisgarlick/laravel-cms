<?php

declare(strict_types=1);

namespace Cg\Cms\Admin;

use Cg\Cms\Models\Entry;
use Cg\Cms\Models\FormSubmission;
use Cg\Cms\Models\Media;
use Cg\Cms\Models\SeoIssue;
use Cg\Cms\Security\DependencyAudit;
use Illuminate\Support\Facades\DB;

/**
 * The numbers beside the sidebar's links.
 *
 * Four cheap queries, run on every full admin page load and skipped on
 * partial reloads (the prop is a closure). Not cached: a count that is a
 * minute behind the save that changed it is a count somebody stops trusting.
 *
 * Counts answer "how many", attention flags answer "is there something to
 * do", and only the second earns the Ember dot.
 */
final class NavCounts
{
    /**
     * @return array{collections: array<string, array{total: int, drafts: int}>, leads: int, leadsAttention: bool, seoBlocking: int, queue: array{pending: int, failed: int, stalled: bool}, mediaMissingAlt: int, security: int}
     */
    public function all(): array
    {
        $collections = [];

        $rows = Entry::query()
            ->select('collection', DB::raw('count(*) as total'), DB::raw("count(*) filter (where status <> 'published') as drafts"))
            ->groupBy('collection')
            ->get();

        foreach ($rows as $row) {
            $collections[(string) $row->collection] = [
                'total' => (int) $row->getAttribute('total'),
                'drafts' => (int) $row->getAttribute('drafts'),
            ];
        }

        $leads = FormSubmission::query()
            ->whereNull('rejected_for')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        return [
            'collections' => $collections,
            'leads' => $leads,

            // A lead accepted but never emailed is the one always-urgent number.
            'leadsAttention' => FormSubmission::query()
                ->whereNull('rejected_for')
                ->whereNull('notified_at')
                ->where('created_at', '<', now()->subMinutes(10))
                ->exists(),

            'seoBlocking' => SeoIssue::query()->where('severity', 'block')->count(),

            'queue' => $this->queue(),

            // Advisories against production dependencies, from the last check.
            'security' => app(DependencyAudit::class)->productionCount(),

            'mediaMissingAlt' => Media::query()
                ->where(fn ($query) => $query->whereNull('alt')->orWhere('alt', ''))
                ->count(),
        ];
    }

    /**
     * Failed jobs, and jobs waiting with no worker to run them. Both mean
     * something the site promised (an email, an image size) is not happening.
     *
     * @return array{pending: int, failed: int, stalled: bool}
     */
    private function queue(): array
    {
        $monitor = app(QueueMonitor::class);
        $counts = $monitor->counts();

        return [
            ...$counts,
            'stalled' => $counts['pending'] > 0 && ! $monitor->workerRunning(),
        ];
    }
}
