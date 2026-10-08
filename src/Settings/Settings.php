<?php

declare(strict_types=1);

namespace Cg\Cms\Settings;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Editable settings, overlaid on config.
 *
 * Config stays the source of defaults and the only thing that exists on a
 * fresh install. A row in `cms_settings` overrides one, and deleting it
 * reverts. That ordering is what lets this ship without a seeder and without a
 * settings screen being a prerequisite for the site working.
 *
 * **Why not just put it all in config.** Because changing a meta description
 * would then be a deploy, and `config:cache` means editing the file on the
 * server does nothing until the cache is rebuilt. The whole point of a CMS is
 * that content changes are not deploys, and the site's own description is
 * content by any reasonable definition.
 *
 * **Why the cache matters more than usual here.** These are read while
 * rendering the head of every page. Without caching that is a query per
 * request on a box with one vCPU, on the hot path, for data that changes a
 * few times a year. The whole table is one small row set, so it is cached as
 * a single array rather than key by key: one lookup, one invalidation.
 */
final class Settings
{
    public const CACHE_KEY = 'cms:settings';

    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    /**
     * A setting, or the config default behind it.
     *
     * Keys are the config keys they override, minus the `cg-cms.` prefix, so
     * `site.name` here is `cg-cms.site.name` in config. One naming scheme
     * rather than a mapping table nobody can remember.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $overrides = $this->all();

        if (array_key_exists($key, $overrides)) {
            return $overrides[$key];
        }

        return config('cg-cms.'.$key, $default);
    }

    /**
     * Every override.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        return $this->loaded = Cache::rememberForever(self::CACHE_KEY, function (): array {
            try {
                return DB::table('cms_settings')
                    ->pluck('value', 'key')
                    ->map(fn (string $value): mixed => json_decode($value, true))
                    ->all();
            } catch (Throwable) {
                // Before the migration has run, or during one. Settings are an
                // overlay on config, so an unavailable table means the site
                // runs on its defaults rather than failing to boot.
                return [];
            }
        });
    }

    /**
     * Write overrides.
     *
     * A value identical to the config default deletes the row instead of
     * storing it, so `all()` stays a list of genuine differences. Without
     * that, saving a settings form once would pin every field at whatever it
     * happened to be, and a later change to a shipped default would silently
     * have no effect.
     *
     * @param  array<string, mixed>  $values
     */
    public function put(array $values): void
    {
        $now = now();

        foreach ($values as $key => $value) {
            if ($this->matchesConfigDefault($key, $value)) {
                DB::table('cms_settings')->where('key', $key)->delete();

                continue;
            }

            DB::table('cms_settings')->upsert(
                [[
                    'key' => $key,
                    'value' => json_encode($value, JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                uniqueBy: ['key'],
                update: ['value', 'updated_at'],
            );
        }

        $this->flush();
    }

    public function forget(string $key): void
    {
        DB::table('cms_settings')->where('key', $key)->delete();

        $this->flush();
    }

    public function flush(): void
    {
        $this->loaded = null;

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Site identity and SEO defaults, with overrides applied.
     *
     * The shape SeoResolver, JsonLd and SeoFileWriter are constructed with.
     * Building it here rather than in the service provider means there is one
     * definition of what those services read, and settings reach all three
     * without any of them knowing settings exist.
     *
     * @return array<string, mixed>
     */
    public function seoConfig(): array
    {
        $merged = [
            ...(array) config('cg-cms.site', []),
            ...(array) config('cg-cms.seo', []),
        ];

        foreach ($this->all() as $key => $value) {
            // `site.name` and `seo.description` both flatten into the same
            // array, matching how the SEO services read them.
            //
            // data_set rather than an array assignment, because some of these
            // are nested: `seo.person.name` has to land at `person.name` in
            // the merged array, which is where JsonLd looks for it.
            if (str_starts_with($key, 'site.') || str_starts_with($key, 'seo.')) {
                data_set($merged, substr($key, strpos($key, '.') + 1), $value);
            }
        }

        return $merged;
    }

    private function matchesConfigDefault(string $key, mixed $value): bool
    {
        $default = config('cg-cms.'.$key);

        // Loose on purpose for the empty cases: a form posts '' where config
        // holds null, and treating those as different would store an override
        // that changes nothing.
        if (($value === '' || $value === null) && ($default === '' || $default === null)) {
            return true;
        }

        return $value === $default;
    }
}
