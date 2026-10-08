<?php

declare(strict_types=1);

namespace Cg\Cms\Http\Controllers\Admin;

use Cg\Cms\Models\FormSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Form submissions.
 *
 * The current CMS has no way to see one at all, which is Kritano issue 13:
 * a submission arrives as an email or it does not arrive. An email that fails
 * to send, or lands in spam, is a lead that never existed as far as anyone
 * can tell. The database row is the record; the email is a notification.
 *
 * Rejected submissions are kept and shown separately rather than discarded.
 * Two reasons: a honeypot or timing rule that is slightly too aggressive
 * silently eats real enquiries, and the only way to find that out is to look
 * at what it rejected. And a spike in rejections is worth seeing.
 */
final class SubmissionController
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $form = (string) $request->query('form', '');
        $showRejected = $request->query('rejected') === '1';

        return Inertia::render('Submissions/Index', [
            'filters' => ['form' => $form, 'rejected' => $showRejected],

            // From the definitions rather than from the submissions table, so
            // a form that has never been used still appears. A form nobody has
            // submitted is exactly the one worth noticing.
            'forms' => fn (): array => $this->forms(),

            'submissions' => fn (): array => $this->paginated($request, $form, $showRejected),
        ]);
    }

    public function destroy(FormSubmission $submission): RedirectResponse
    {
        $submission->delete();

        return back()->with('success', 'Submission deleted.');
    }

    /**
     * Download submissions as CSV.
     *
     * Columns are the union of every key present, because a form's fields can
     * change between one submission and the next and the older rows still
     * hold whatever they held. Taking the columns from the current definition
     * would silently drop data from before a field was renamed.
     */
    public function export(Request $request): StreamedResponse
    {
        $form = (string) $request->query('form', '');

        $submissions = FormSubmission::query()
            ->when($form !== '', fn ($query) => $query->form($form))
            ->when($request->query('rejected') !== '1', fn ($query) => $query->accepted())
            ->orderByDesc('created_at')
            ->get();

        $keys = $submissions
            ->flatMap(fn (FormSubmission $submission): array => array_keys((array) $submission->data))
            ->unique()
            ->values()
            ->all();

        $csv = fopen('php://temp', 'r+');

        fputcsv($csv, ['submitted_at', 'form', 'context', ...$keys, 'rejected_for']);

        foreach ($submissions as $submission) {
            fputcsv($csv, [
                $submission->created_at?->toIso8601String(),
                $submission->form,
                $submission->context,
                ...array_map(
                    fn (string $key): string => $this->flatten($submission->data[$key] ?? ''),
                    $keys,
                ),
                $submission->rejected_for,
            ]);
        }

        rewind($csv);
        $contents = (string) stream_get_contents($csv);
        fclose($csv);

        return response()->streamDownload(
            fn () => print ($contents),
            'submissions'.($form !== '' ? '-'.$form : '').'.csv',
            ['Content-Type' => 'text/csv'],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function forms(): array
    {
        $counts = FormSubmission::query()
            ->selectRaw('form, count(*) filter (where rejected_for is null) as accepted, count(*) filter (where rejected_for is not null) as rejected')
            ->groupBy('form')
            ->get()
            ->keyBy('form');

        $forms = [];

        foreach ((array) config('cg-forms', []) as $slug => $definition) {
            $row = $counts->get($slug);

            $forms[] = [
                'slug' => $slug,
                'name' => (string) ($definition['name'] ?? $slug),
                'fields' => array_keys((array) ($definition['fields'] ?? [])),
                'accepted' => (int) ($row->accepted ?? 0),
                'rejected' => (int) ($row->rejected ?? 0),
            ];
        }

        return $forms;
    }

    /**
     * @return array<string, mixed>
     */
    private function paginated(Request $request, string $form, bool $rejected): array
    {
        $page = FormSubmission::query()
            ->when($form !== '', fn ($query) => $query->form($form))
            ->when($rejected, fn ($query) => $query->rejected(), fn ($query) => $query->accepted())
            ->orderByDesc('created_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'data' => $page->getCollection()->map(fn (FormSubmission $submission): array => [
                'id' => $submission->id,
                'form' => $submission->form,
                'context' => $submission->context,
                'data' => $submission->data,
                'referrer' => $submission->referrer,
                'rejectedFor' => $submission->rejected_for,
                'notifiedAt' => $submission->notified_at?->toIso8601String(),
                'submittedAt' => $submission->created_at?->toIso8601String(),
            ])->all(),
            'total' => $page->total(),
            'currentPage' => $page->currentPage(),
            'lastPage' => $page->lastPage(),
        ];
    }

    /** A value as one CSV cell. */
    private function flatten(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(fn (mixed $item): string => (string) $item, $value));
        }

        return is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;
    }
}
