<?php

declare(strict_types=1);

namespace Cg\Cms\Http\Controllers\Admin;

use Cg\Cms\Security\DependencyAudit;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Known security advisories against the installed dependencies.
 *
 * A report, not a control panel: updates are made locally and shipped in a
 * release, never run on the server.
 */
final class SecurityController
{
    public function __construct(private readonly DependencyAudit $audit) {}

    public function index(): Response
    {
        return Inertia::render('Security/Index', [
            'report' => $this->audit->latest(),
        ]);
    }

    public function check(): RedirectResponse
    {
        $report = $this->audit->run();
        $count = count($report['advisories']);

        return back()->with($count === 0 ? 'success' : 'error', $count === 0
            ? 'Checked: no known advisories.'
            : "Checked: {$count} ".($count === 1 ? 'advisory' : 'advisories').' found.');
    }
}
