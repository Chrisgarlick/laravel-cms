<?php

declare(strict_types=1);

namespace Cg\Cms\Console;

use Cg\Cms\Models\SeoIssue;
use Cg\Cms\Seo\Checks\SeoAuditor;
use Illuminate\Console\Command;

/**
 * Render every public page and record what is wrong with it.
 *
 * Scheduled nightly by the package (cg-cms.seo.audit_schedule). Replaces the
 * hand-written audit document: the findings land in seo_issues, the dashboard
 * counts them and the SEO screen lists them, and fixing one makes it disappear
 * on the next run without anybody closing it.
 */
final class SeoAuditCommand extends Command
{
    protected $signature = 'cms:seo-audit';

    protected $description = 'Render every public page and record SEO issues';

    public function handle(SeoAuditor $auditor): int
    {
        $this->components->info('Auditing public pages.');

        $result = $auditor->run();

        $this->components->twoColumnDetail('Pages', (string) $result['pages']);
        $this->components->twoColumnDetail('Internal links', (string) $result['links']);
        $this->components->twoColumnDetail('Issues', (string) $result['issues']);

        if ($result['issues'] === 0) {
            return self::SUCCESS;
        }

        // By rule, most frequent first. The full list is in the admin; the
        // console says what kind of problems there are and how many.
        $this->newLine();

        SeoIssue::query()
            ->selectRaw('rule, severity, count(*) as total')
            ->groupBy('rule', 'severity')
            ->orderByDesc('total')
            ->get()
            ->each(fn ($row) => $this->components->twoColumnDetail(
                $row->rule.($row->severity === 'block' ? ' <fg=red>(blocking)</>' : ''),
                (string) $row->total,
            ));

        // Findings are a report, not a failure. A nightly job that exits
        // non-zero because an article title is 62 characters long would page
        // somebody about something that can wait until morning.
        return self::SUCCESS;
    }
}
