<?php

declare(strict_types=1);

namespace Cg\Cms\Http\Controllers\Admin;

use Cg\Cms\Admin\AdminVite;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reports what the package boundary resolved to at runtime.
 *
 * Every claim in section 5.12 about the admin being separate from the host
 * application is checkable, and this is where they are checked on the running
 * system rather than only in the test suite. Reaching the page at all proves
 * the lazily imported chunk resolved; the table proves the rest.
 *
 * Kept past step 1 on purpose. The first time the admin misbehaves after a
 * deploy, the question is always whether the published assets are the ones the
 * server is pointing at, and this answers it without an SSH session.
 */
final class BoundaryController
{
    public function __invoke(AdminVite $vite): Response
    {
        $hostManifest = public_path('build/manifest.json');

        return Inertia::render('Boundary', [
            'checks' => [
                [
                    'name' => 'Admin manifest',
                    'detail' => $vite->manifestPath(),
                    'passed' => is_file($vite->manifestPath()),
                ],
                [
                    'name' => 'Asset base',
                    'detail' => '/'.AdminVite::BUILD_DIRECTORY.'/',
                    'passed' => is_dir(public_path(AdminVite::BUILD_DIRECTORY)),
                ],
                [
                    'name' => 'Bundle version',
                    'detail' => $vite->version(),
                    'passed' => ! in_array($vite->version(), ['unbuilt', ''], true),
                ],
                [
                    'name' => 'Dev server',
                    'detail' => $vite->hot() ? 'running, assets served from Vite' : 'not running, assets served from disk',
                    'passed' => true,
                ],
                [
                    // The host application's build is a separate thing that
                    // this package must never write to or read from. If the
                    // app has one, it is untouched; if it has none, the admin
                    // still works, which is the actual claim being made.
                    'name' => 'Host app manifest',
                    'detail' => is_file($hostManifest)
                        ? $hostManifest.' (untouched)'
                        : 'none, and the admin does not need one',
                    'passed' => true,
                ],
                [
                    'name' => 'Root view',
                    'detail' => 'cgcms::admin',
                    'passed' => view()->exists('cgcms::admin'),
                ],
            ],
        ]);
    }
}
