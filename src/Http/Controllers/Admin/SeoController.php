<?php

declare(strict_types=1);

namespace Cg\Cms\Http\Controllers\Admin;

use Cg\Cms\Jobs\RunSeoAudit;
use Cg\Cms\Models\SeoIssue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the last audit found.
 *
 * Section 5.12 only asks for an issue count on the dashboard, but a count you
 * cannot click through is a number that makes people anxious without telling
 * them what to do. This is that click-through, and it is deliberately a list
 * rather than a report: grouped by rule, filterable, each row linking to the
 * entry that needs the fix.
 */
final class SeoController
{
    private const LIMIT = 500;

    public function index(Request $request): Response
    {
        $rule = $request->query('rule');
        $rule = is_string($rule) && $rule !== '' ? $rule : null;

        return Inertia::render('Seo/Index', [
            'rule' => $rule,

            'summary' => SeoIssue::query()
                ->selectRaw('rule, severity, count(*) as total')
                ->groupBy('rule', 'severity')
                ->orderByRaw("case when severity = 'block' then 0 else 1 end")
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row): array => [
                    'rule' => $row->rule,
                    'severity' => $row->severity,
                    'total' => (int) $row->total,
                ])
                ->all(),

            'issues' => fn (): array => $this->issues($rule),

            'total' => SeoIssue::query()->when($rule, fn ($q) => $q->where('rule', $rule))->count(),
            'limit' => self::LIMIT,
            'auditedAt' => SeoIssue::query()->max('found_at'),
        ]);
    }

    public function audit(): RedirectResponse
    {
        RunSeoAudit::dispatch();

        // A named route rather than back(). On a sync queue the audit has
        // just rendered every page in this process, and although the auditor
        // restores the request it found, there is no reason to lean on that
        // to work out where to send somebody.
        return redirect()
            ->route('cgcms.admin.seo.index')
            ->with('success', 'Audit queued. Results replace this list when it finishes.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function issues(?string $rule): array
    {
        return SeoIssue::query()
            ->with('entry:id,collection,title')
            ->when($rule, fn ($q) => $q->where('rule', $rule))
            ->orderByRaw("case when severity = 'block' then 0 else 1 end")
            ->orderBy('rule')
            ->orderBy('url')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (SeoIssue $issue): array => [
                'id' => $issue->id,
                'url' => $issue->url,
                'rule' => $issue->rule,
                'severity' => $issue->severity,
                'message' => $issue->message,
                'detail' => $issue->detail,
                'entry' => $issue->entry === null ? null : [
                    'id' => $issue->entry->id,
                    'collection' => $issue->entry->collection,
                    'title' => $issue->entry->title,
                ],
            ])
            ->all();
    }
}
