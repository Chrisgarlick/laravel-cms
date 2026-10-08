<?php

declare(strict_types=1);

namespace Cg\Cms\Http\Controllers\Admin;

use Cg\Cms\Admin\RedirectCsv;
use Cg\Cms\Models\NotFoundEntry;
use Cg\Cms\Models\Redirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Redirects, and the 404s that should become them.
 *
 * Two tabs on one screen rather than two screens, because they are one task.
 * A 404 log you have to go and look at is a 404 log nobody looks at; the
 * reason to record unmatched URLs at all is that turning one into a redirect
 * should be a single click from where you noticed it.
 */
final class RedirectController
{
    public function index(Request $request): Response
    {
        return Inertia::render('Redirects/Index', [
            'tab' => $request->query('tab') === 'not-found' ? 'not-found' : 'redirects',

            'redirects' => fn (): array => Redirect::query()
                ->orderByDesc('hits')
                ->orderBy('from')
                ->limit(500)
                ->get()
                ->map(fn (Redirect $redirect): array => [
                    'id' => $redirect->id,
                    'from' => $redirect->from,
                    'to' => $redirect->to,
                    'status' => $redirect->status,
                    'matchType' => $redirect->match_type,
                    'force' => $redirect->force,
                    'hits' => $redirect->hits,
                    'lastHitAt' => $redirect->last_hit_at?->toIso8601String(),
                    'notes' => $redirect->notes,

                    // Created by a slug change rather than by a person. Worth
                    // distinguishing: these maintain themselves and editing
                    // one by hand is usually a mistake.
                    'automatic' => $redirect->entry_id !== null,
                ])
                ->all(),

            'notFound' => fn (): array => NotFoundEntry::query()
                ->orderByDesc('hits')
                ->limit(200)
                ->get()
                ->map(fn (NotFoundEntry $miss): array => [
                    'id' => $miss->id,
                    'path' => $miss->path,
                    'hits' => $miss->hits,
                    'lastReferrer' => $miss->last_referrer,
                    'lastSeenAt' => $miss->last_seen_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Redirect::query()->create($this->validated($request));

        return back()->with('success', 'Redirect created.');
    }

    public function update(Request $request, Redirect $redirect): RedirectResponse
    {
        $redirect->update($this->validated($request, $redirect));

        return back()->with('success', 'Redirect updated.');
    }

    public function destroy(Redirect $redirect): RedirectResponse
    {
        $redirect->delete();

        return back()->with('success', 'Redirect deleted.');
    }

    /**
     * Turn a logged 404 into a redirect, and clear it from the log.
     *
     * Clearing matters. Leaving it there means the list still shows a problem
     * that has been solved, and a list of solved problems is one people stop
     * reading.
     */
    public function resolveNotFound(Request $request, NotFoundEntry $notFound): RedirectResponse
    {
        $validated = $request->validate([
            'to' => ['required', 'string', 'max:2048'],
            'status' => ['sometimes', Rule::in([301, 302, 307, 308, 410])],
        ]);

        Redirect::query()->updateOrCreate(
            ['from' => $notFound->path],
            [
                'to' => $validated['to'],
                'status' => $validated['status'] ?? 301,
                'match_type' => 'exact',
                'notes' => 'Created from the 404 log.',
            ],
        );

        $notFound->delete();

        return back()->with('success', 'Redirect created from '.$notFound->path);
    }

    public function forgetNotFound(NotFoundEntry $notFound): RedirectResponse
    {
        $notFound->delete();

        return back()->with('success', 'Removed from the log.');
    }

    public function export(RedirectCsv $csv): StreamedResponse
    {
        $contents = $csv->export();

        return response()->streamDownload(
            fn () => print ($contents),
            'redirects.csv',
            ['Content-Type' => 'text/csv'],
        );
    }

    public function import(Request $request, RedirectCsv $csv): RedirectResponse
    {
        $request->validate([
            // Not 'mimes:csv'. A CSV exported from Excel or Numbers is
            // routinely sniffed as text/plain or application/vnd.ms-excel,
            // and rejecting the file somebody just exported is the fastest way
            // to make an import feature unusable.
            'file' => ['required', 'file', 'max:2048'],
        ]);

        $result = $csv->import((string) file_get_contents($request->file('file')->getRealPath()));

        if ($result['skipped'] !== []) {
            return back()->with('error', sprintf(
                'Imported %d. Skipped %d: %s',
                $result['imported'],
                count($result['skipped']),
                implode(' ', array_slice($result['skipped'], 0, 3)),
            ));
        }

        return back()->with('success', sprintf('Imported %d redirect(s).', $result['imported']));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Redirect $existing = null): array
    {
        $validated = $request->validate([
            'from' => [
                'required',
                'string',
                'max:2048',
                Rule::unique('redirects', 'from')->ignore($existing?->id),
            ],
            'to' => ['required', 'string', 'max:2048'],
            'status' => ['required', Rule::in([301, 302, 307, 308, 410])],
            'match_type' => ['required', Rule::in(['exact', 'prefix', 'regex'])],
            'force' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $validated['from'] = '/'.ltrim(trim($validated['from']), '/');

        // A self-referential redirect is an infinite loop, which a browser
        // reports as a broken site rather than as a CMS mistake. Thrown as a
        // validation error rather than aborted, so it lands on the field in
        // the form instead of on an error page.
        if ($validated['from'] === $validated['to']) {
            throw ValidationException::withMessages([
                'to' => 'A redirect cannot point at itself.',
            ]);
        }

        return $validated;
    }
}
