<?php

declare(strict_types=1);

namespace Cg\Cms\Admin;

use Cg\Cms\Models\Redirect;
use Illuminate\Support\Collection;

/**
 * Redirects in and out as CSV.
 *
 * This matters for the cutover more than for day-to-day work. Moving a site
 * means arriving with a list of old URLs from Search Console, a crawl, or the
 * previous stack's config, and typing several hundred of them into a form is
 * not a plan. Export exists so the list can be checked in a spreadsheet, which
 * is what people actually do with it.
 */
final class RedirectCsv
{
    private const COLUMNS = ['from', 'to', 'status', 'match_type', 'force', 'notes'];

    public function export(): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [...self::COLUMNS, 'hits', 'last_hit_at']);

        Redirect::query()
            ->orderBy('from')
            ->each(function (Redirect $redirect) use ($handle): void {
                fputcsv($handle, [
                    $redirect->from,
                    $redirect->to,
                    $redirect->status,
                    $redirect->match_type,
                    $redirect->force ? '1' : '0',
                    $redirect->notes,
                    $redirect->hits,
                    $redirect->last_hit_at?->toIso8601String(),
                ]);
            });

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Read a CSV into redirects.
     *
     * Reports per row rather than failing the file. A 300-row import that
     * rejects everything because row 174 has a typo is an import nobody can
     * use: they have no way to find row 174 except by bisecting the file.
     *
     * Imports are upserts keyed on `from`, so re-importing a corrected file is
     * the fix rather than a source of duplicates.
     *
     * @return array{imported: int, skipped: array<int, string>}
     */
    public function import(string $csv): array
    {
        $rows = $this->parse($csv);

        if ($rows->isEmpty()) {
            return ['imported' => 0, 'skipped' => ['The file had no rows.']];
        }

        $imported = 0;
        $skipped = [];

        foreach ($rows as $number => $row) {
            $from = $this->path($row['from'] ?? '');
            $to = trim((string) ($row['to'] ?? ''));

            if ($from === '' || $to === '') {
                $skipped[] = "Row {$number}: needs both a from and a to.";

                continue;
            }

            if ($from === $to) {
                // nginx and the application would both follow this forever.
                $skipped[] = "Row {$number}: {$from} points at itself.";

                continue;
            }

            $status = (int) ($row['status'] ?? 301);
            $matchType = (string) ($row['match_type'] ?? 'exact');

            if (! in_array($matchType, ['exact', 'prefix', 'regex'], true)) {
                $skipped[] = "Row {$number}: unknown match type [{$matchType}].";

                continue;
            }

            Redirect::query()->updateOrCreate(['from' => $from], [
                'to' => $to,
                'status' => in_array($status, [301, 302, 307, 308, 410], true) ? $status : 301,
                'match_type' => $matchType,
                'force' => filter_var($row['force'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'notes' => $row['notes'] ?? 'Imported.',
            ]);

            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * @return Collection<int, array<string, string>>
     */
    private function parse(string $csv): Collection
    {
        $lines = preg_split('/\R/', trim($csv)) ?: [];

        if ($lines === []) {
            return collect();
        }

        $header = array_map(
            fn (string $column): string => strtolower(trim($column)),
            str_getcsv((string) array_shift($lines), escape: '\\'),
        );

        $rows = collect();

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line, escape: '\\');
            $row = [];

            foreach ($header as $position => $column) {
                $row[$column] = trim((string) ($values[$position] ?? ''));
            }

            // Numbered as a human counts lines in the file they are looking
            // at: the header is line 1, so the first data row is line 2.
            $rows[$index + 2] = $row;
        }

        return $rows;
    }

    /** Normalise a URL or path to the leading-slash path the resolver matches. */
    private function path(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        // Exports from Search Console and crawlers carry full URLs. Storing
        // one would mean it never matches, because resolution compares against
        // the request path.
        $path = str_starts_with($value, 'http') ? (parse_url($value, PHP_URL_PATH) ?: '/') : $value;

        return '/'.ltrim((string) $path, '/');
    }
}
