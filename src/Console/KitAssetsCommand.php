<?php

declare(strict_types=1);

namespace Cg\Cms\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Copies the CSS and fonts of a `kit build-cgcms` folder into public/, where
 * nginx serves them as static files.
 *
 * The folder holds PHP (kit's runtime, the compiled sections) and JSON (the
 * schemas) beside the stylesheets, and none of that may be web-reachable, so
 * the folder is never linked into public/ whole. Only these are copied:
 *
 *   kit/*.css                  kit.css: tokens and the components sections use
 *   kit/fonts/*                the fonts kit.css loads (woff2, woff, ttf, otf)
 *   sections/<name>/style.css  each section's CSS, pushed by its block's view
 *
 * to the same relative paths under public/<to>, which is what
 * `config('kit-blocks.url')` (default /kit-blocks) points at. The target is
 * emptied first, so a section removed from kit leaves no stylesheet behind.
 * Run it after every `kit build-cgcms` (and on deploy, if public/<to> is not
 * committed). See KIT-BLOCKS.md.
 */
final class KitAssetsCommand extends Command
{
    protected $signature = 'cms:kit-assets
        {source? : The kit build-cgcms folder (default: base_path("kit-blocks"))}
        {--to= : The folder under public/ (default: the path of config("kit-blocks.url"), else kit-blocks)}';

    protected $description = 'Copy kit blocks\' CSS and fonts (never their PHP) into public/';

    private const FONT_EXTENSIONS = ['woff2', 'woff', 'ttf', 'otf'];

    public function handle(): int
    {
        $source = rtrim((string) ($this->argument('source') ?? base_path('kit-blocks')), '/');

        if (! is_file($source.'/kit/kit.css') || ! is_dir($source.'/sections')) {
            $this->components->error("{$source} is not a kit build-cgcms folder (no kit/kit.css and sections/).");

            return self::FAILURE;
        }

        $to = trim((string) ($this->option('to') ?? parse_url((string) config('kit-blocks.url', '/kit-blocks'), PHP_URL_PATH) ?? 'kit-blocks'), '/');

        if ($to === '' || str_contains($to, '..')) {
            $this->components->error('The target must be a folder inside public/, not public/ itself.');

            return self::FAILURE;
        }

        $target = public_path($to);
        $files = $this->files($source);

        // Emptying the target is only safe while it holds nothing but what
        // this command copies: `--to=vendor` must not take the admin with it.
        foreach (is_dir($target) ? File::allFiles($target) : [] as $existing) {
            if (! in_array(strtolower($existing->getExtension()), ['css', ...self::FONT_EXTENSIONS], true)) {
                $this->components->error("public/{$to} holds other files ({$existing->getRelativePathname()}); choose an empty folder.");

                return self::FAILURE;
            }
        }

        File::deleteDirectory($target);

        foreach ($files as $relative) {
            File::ensureDirectoryExists(dirname($target.'/'.$relative));
            File::copy($source.'/'.$relative, $target.'/'.$relative);
        }

        $this->components->info(sprintf('Copied %d kit stylesheets and fonts to public/%s.', count($files), $to));

        return self::SUCCESS;
    }

    /**
     * What may be served, relative to the folder.
     *
     * @return array<int, string>
     */
    private function files(string $source): array
    {
        $files = array_map(fn (string $path): string => 'kit/'.basename($path), glob($source.'/kit/*.css') ?: []);

        foreach (glob($source.'/kit/fonts/*') ?: [] as $font) {
            if (is_file($font) && in_array(strtolower(pathinfo($font, PATHINFO_EXTENSION)), self::FONT_EXTENSIONS, true)) {
                $files[] = 'kit/fonts/'.basename($font);
            }
        }

        foreach (glob($source.'/sections/*/style.css') ?: [] as $style) {
            $files[] = 'sections/'.basename(dirname($style)).'/style.css';
        }

        sort($files);

        return $files;
    }
}
