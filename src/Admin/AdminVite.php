<?php

declare(strict_types=1);

namespace Cg\Cms\Admin;

use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Asset resolution for the admin bundle, independent of the host application's.
 *
 * This is the load-bearing half of the package boundary. Laravel's Vite helper
 * is instantiable rather than static, so the package can resolve its own
 * instance pointed at its own hot file, build directory and manifest. The host
 * application's `@vite` directive and this never meet: two manifests, two build
 * directories, two dev servers, no shared state.
 *
 * Without that, shipping an Inertia app from inside a Composer package would
 * force every consuming application to add the admin entry point to its own
 * Vite config, install Node dependencies it does not otherwise need, and
 * rebuild its front end whenever the CMS changed.
 */
final class AdminVite
{
    /**
     * Where published assets live, relative to public/.
     *
     * Matches the `base` in vite.admin.config.ts. These two are one decision
     * expressed in two languages, and a mismatch shows up as the first screen
     * working and every lazily imported screen after it 404ing.
     */
    public const BUILD_DIRECTORY = 'vendor/cg-cms';

    public const ENTRY = 'resources/js/admin/app.tsx';

    /**
     * Tags for the admin entry point.
     *
     * Returns an empty string rather than throwing when the manifest is
     * missing. An admin that renders a bare page with a visible warning is
     * recoverable by running `vendor:publish`; a 500 during the request that
     * would have told you that is not.
     */
    public function tags(): HtmlString
    {
        try {
            return new HtmlString((string) $this->vite()(self::ENTRY));
        } catch (Throwable) {
            return new HtmlString('');
        }
    }

    /**
     * The React Refresh preamble, when a dev server is running.
     *
     * Laravel's `@viteReactRefresh` directive is bound to the default Vite
     * instance and would emit the host application's hot file, so the admin
     * emits its own. Without it, HMR reloads the page instead of the component
     * and every edit loses the editor's state.
     */
    public function reactRefresh(): HtmlString
    {
        if (! $this->hot()) {
            return new HtmlString('');
        }

        $url = $this->devServerUrl();

        return new HtmlString(<<<HTML
            <script type="module">
                import RefreshRuntime from '{$url}/@react-refresh'
                RefreshRuntime.injectIntoGlobalHook(window)
                window.\$RefreshReg\$ = () => {}
                window.\$RefreshSig\$ = () => (type) => type
                window.__vite_plugin_react_preamble_installed__ = true
            </script>
            HTML);
    }

    /**
     * Just the stylesheet, with no bundle behind it.
     *
     * The sign-in screen needs to look like the admin while loading none of
     * its JavaScript, so it cannot use tags(). Resolved from the manifest's
     * `css` list for the entry point rather than a hardcoded filename, because
     * the built name carries a content hash.
     *
     * In dev, Vite serves a stylesheet as a JavaScript module that injects
     * itself, which a <link> element will refuse. `?direct` is the dev
     * server's way of asking for the raw CSS instead.
     */
    public function stylesheetTags(): HtmlString
    {
        if ($this->hot()) {
            return new HtmlString(sprintf(
                '<link rel="stylesheet" href="%s/%s?direct">',
                e($this->devServerUrl()),
                e(dirname(self::ENTRY).'/admin.css'),
            ));
        }

        $tags = '';

        foreach ($this->entryStylesheets() as $file) {
            $tags .= sprintf('<link rel="stylesheet" href="/%s/%s">', e(self::BUILD_DIRECTORY), e($file));
        }

        return new HtmlString($tags);
    }

    /** True when the package's own `npm run dev` is running. */
    public function hot(): bool
    {
        return is_file($this->hotFile());
    }

    /**
     * Have the assets been published?
     *
     * Checked by the root view so a missing `vendor:publish` produces a message
     * saying exactly that, rather than a blank screen and a console full of
     * 404s. This is the single most likely thing to go wrong on a first deploy.
     */
    public function published(): bool
    {
        return $this->hot() || is_file(public_path(self::BUILD_DIRECTORY.'/manifest.json'));
    }

    public function manifestPath(): string
    {
        return public_path(self::BUILD_DIRECTORY.'/manifest.json');
    }

    /**
     * A version string that changes when the bundle does.
     *
     * Inertia sends this with every response and the client does a hard reload
     * when it changes, which is what stops a visitor's open admin tab from
     * talking to a deployed version whose props have a different shape. Derived
     * from the manifest's own contents, so it changes exactly when the assets
     * change and never otherwise.
     */
    public function version(): string
    {
        if ($this->hot()) {
            return 'dev';
        }

        $manifest = $this->manifestPath();

        return is_file($manifest) ? (string) md5_file($manifest) : 'unbuilt';
    }

    /**
     * The package's own Vite instance.
     *
     * Three calls are the whole boundary: a hot file inside the package, a
     * build directory under public/vendor, and a manifest named predictably.
     */
    private function vite(): Vite
    {
        return app(Vite::class)
            ->useHotFile($this->hotFile())
            ->useBuildDirectory(self::BUILD_DIRECTORY)
            ->useManifestFilename('manifest.json');
    }

    /**
     * The hot file, inside the package rather than in public/.
     *
     * It is a development artefact of the package, it is never published, and
     * putting it in public/ would mean a stray file on a production box could
     * point the admin at somebody's laptop.
     */
    private function hotFile(): string
    {
        return dirname(__DIR__, 2).'/dist/hot';
    }

    private function devServerUrl(): string
    {
        $contents = @file_get_contents($this->hotFile());

        return rtrim(is_string($contents) ? trim($contents) : '', '/');
    }

    /**
     * Stylesheets the built entry point emitted.
     *
     * @return array<int, string>
     */
    private function entryStylesheets(): array
    {
        $manifest = $this->manifestPath();

        if (! is_file($manifest)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($manifest), true);

        if (! is_array($decoded)) {
            return [];
        }

        $css = $decoded[self::ENTRY]['css'] ?? [];

        return is_array($css) ? array_values(array_map('strval', $css)) : [];
    }
}
