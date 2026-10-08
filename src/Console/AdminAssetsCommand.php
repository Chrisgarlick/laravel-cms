<?php

declare(strict_types=1);

namespace Cg\Cms\Console;

use Cg\Cms\Admin\AdminVite;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Reports whether the published admin bundle matches the admin source.
 *
 * Committing `dist/` is what lets the package install without Node, and the
 * price is that the bundle can silently fall behind the code it was built
 * from. Nothing about a stale bundle looks wrong: the admin loads, the screens
 * render, and the behaviour is last week's.
 *
 * Two comparisons, because there are two places it can drift:
 *
 *   - `dist/` against the sources it was built from, caught by a content hash
 *     the Vite build writes. Fails when somebody edited a component and did
 *     not rebuild.
 *   - `public/vendor/cg-cms/` against `dist/`, caught by comparing manifests.
 *     Fails when somebody deployed without `vendor:publish`.
 *
 * Hashes rather than timestamps throughout. Git does not preserve mtimes, so a
 * fresh clone would report every file as newer than the build, and a check
 * that cries wolf on a clean checkout is a check that gets removed.
 */
final class AdminAssetsCommand extends Command
{
    protected $signature = 'cms:admin-assets
        {--check : Exit non-zero if the bundle is stale. For CI}
        {--publish : Republish dist/ into public/ before reporting}';

    protected $description = 'Check that the built admin bundle matches its source and is published';

    public function handle(AdminVite $vite): int
    {
        if ($this->option('publish')) {
            $removed = $this->clearPublished($vite);

            Artisan::call('vendor:publish', ['--tag' => 'cg-cms-assets', '--force' => true]);

            $this->components->info(sprintf(
                'Republished admin assets%s.',
                $removed > 0 ? sprintf(', after removing %d stale file(s)', $removed) : '',
            ));
        }

        $problems = [];

        $built = $this->builtSourceHash();
        $current = $this->currentSourceHash();

        if ($built === null) {
            $problems[] = 'dist/ has never been built. Run `npm run build` in the package.';
        } elseif ($current !== null && $built !== $current) {
            $problems[] = 'dist/ is older than resources/js/admin. Run `npm run build` in the package.';
        }

        $distManifest = dirname(__DIR__, 2).'/dist/manifest.json';
        $publicManifest = $vite->manifestPath();

        if (! is_file($publicManifest)) {
            $problems[] = 'Admin assets are not published. Run `php artisan vendor:publish --tag=cg-cms-assets --force`.';
        } elseif (is_file($distManifest) && md5_file($distManifest) !== md5_file($publicManifest)) {
            $problems[] = 'public/vendor/cg-cms is out of date with dist/. Run `php artisan vendor:publish --tag=cg-cms-assets --force`.';
        }

        $this->components->twoColumnDetail('Source hash', $current === null ? 'unknown' : substr($current, 0, 12));
        $this->components->twoColumnDetail('Built from', $built === null ? 'never built' : substr($built, 0, 12));
        $this->components->twoColumnDetail('Published', is_file($publicManifest) ? 'yes' : '<fg=red>no</>');
        $this->components->twoColumnDetail('Bundle version', $vite->version());

        if ($problems === []) {
            $this->newLine();
            $this->components->info('Admin assets are current.');

            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        // Only --check fails the process. Run bare, this is a status report,
        // and a report that exits non-zero breaks any script that runs it for
        // information.
        return $this->option('check') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Empty the published assets directory before republishing.
     *
     * `vendor:publish --force` overwrites what it copies and removes nothing,
     * and every build produces new content-hashed filenames. Without this the
     * directory grows by a full bundle on every deploy: after a dozen
     * deployments the 1GB box is storing a dozen copies of TipTap, none of
     * which any page references.
     *
     * It also hides staleness. A published directory holding four versions of
     * a chunk looks identical to one holding the right one, so the only
     * evidence that a deploy did not publish is a 404 in somebody's console.
     *
     * Scoped to the package's own published directory, which is recreated by
     * the publish on the next line, and never touches anything else in
     * public/.
     */
    private function clearPublished(AdminVite $vite): int
    {
        $directory = public_path(AdminVite::BUILD_DIRECTORY);

        if (! is_dir($directory)) {
            return 0;
        }

        $removed = 0;

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : (@unlink($file->getPathname()) && $removed++);
        }

        return $removed;
    }

    /** The hash recorded when the bundle was last built. */
    private function builtSourceHash(): ?string
    {
        $path = dirname(__DIR__, 2).'/dist/.sources';

        if (! is_file($path)) {
            return null;
        }

        $hash = trim((string) file_get_contents($path));

        return $hash === '' ? null : $hash;
    }

    /**
     * The hash of the sources as they stand now.
     *
     * Must produce the same digest as the Vite plugin in
     * vite.admin.config.ts: files walked in sorted order, relative path then
     * contents into a single sha256. Returns null when the sources are absent,
     * which is the normal case for an installed package whose vendor directory
     * ships without them.
     */
    private function currentSourceHash(): ?string
    {
        $root = dirname(__DIR__, 2).'/resources/js/admin';

        if (! is_dir($root)) {
            return null;
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        // Byte-wise sort of the relative paths. The Vite plugin sorts the same
        // way, on the same strings, for the reason spelled out there: sorting
        // per directory during the walk orders `pages/` before `pages.ts`,
        // while this orders `pages.ts` first, and the two digests would then
        // disagree forever.
        sort($files, SORT_STRING);

        $hash = hash_init('sha256');

        foreach ($files as $relative) {
            hash_update($hash, $relative);
            hash_update_file($hash, $root.'/'.$relative);
        }

        return hash_final($hash);
    }
}
