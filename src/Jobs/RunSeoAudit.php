<?php

declare(strict_types=1);

namespace Cg\Cms\Jobs;

use Cg\Cms\Seo\Checks\SeoAuditor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The audit, from the admin's "run now" button.
 *
 * Queued because it renders every page on the site, which on a single vCPU
 * takes long enough that holding a PHP-FPM worker for it would hold one a
 * visitor needs. Unique, so impatient clicking queues one audit, not five.
 */
final class RunSeoAudit implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 600;

    public function handle(SeoAuditor $auditor): void
    {
        $auditor->run();
    }
}
