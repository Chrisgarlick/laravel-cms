<?php

declare(strict_types=1);

namespace Cg\Cms\Http\Controllers\Admin;

use Cg\Cms\Cache\CachePurger;
use Cg\Cms\Models\PageCacheEntry;
use Cg\Cms\Seo\SeoFileWriter;
use Cg\Cms\Settings\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Site settings, and the cache controls.
 *
 * Only the settings worth changing without a deploy are here. Anything that
 * changes how the application is wired stays in config, where it is reviewable
 * and deployed with the code that depends on it: a settings screen that can
 * repoint the database or turn off the page cache is a settings screen that
 * can take the site down from a browser.
 *
 * Every field is an override with a config default behind it. Clearing one
 * reverts to the shipped value rather than blanking it, which is why the
 * inputs show the default as a placeholder.
 */
final class SettingsController
{
    /**
     * What may be edited, and how it is validated.
     *
     * Keyed by config key without the `cg-cms.` prefix. A key absent from here
     * cannot be written however the form is posted, which is the point: the
     * request is not trusted to say what a setting is.
     *
     * @return array<string, array<int, string>>
     */
    private function editable(): array
    {
        return [
            'site.name' => ['nullable', 'string', 'max:120'],
            'site.domain' => ['nullable', 'string', 'max:255'],
            'site.contact_email' => ['nullable', 'email:rfc', 'max:255'],

            'seo.title_template' => ['nullable', 'string', 'max:120'],
            'seo.description' => ['nullable', 'string', 'max:300'],
            'seo.image' => ['nullable', 'string', 'max:512'],
            'seo.image_alt' => ['nullable', 'string', 'max:255'],
            'seo.twitter' => ['nullable', 'string', 'max:64'],
            'seo.search_url' => ['nullable', 'string', 'max:255'],
            'seo.area_served' => ['nullable', 'string', 'max:120'],
            'seo.person.name' => ['nullable', 'string', 'max:120'],
            'seo.person.job_title' => ['nullable', 'string', 'max:120'],
            'seo.organization.name' => ['nullable', 'string', 'max:120'],
            'seo.organization.logo' => ['nullable', 'string', 'max:512'],
        ];
    }

    /**
     * The same rules, keyed for the validator.
     *
     * Every dot is escaped. A setting key *is* a dotted config path, and an
     * unescaped dot in a rule key means something else entirely to Laravel: it
     * reads `site.name` as "the `name` key inside the `site` array", so a form
     * posting a flat `site.name` matches nothing, validates to an empty array,
     * and saves silently without error. Escaped, the validator treats it as one
     * literal key and hands it back flat.
     *
     * @return array<string, array<int, string>>
     */
    private function rules(): array
    {
        $rules = [];

        foreach ($this->editable() as $key => $rule) {
            $rules[str_replace('.', '\.', $key)] = $rule;
        }

        return $rules;
    }

    public function index(Settings $settings): Response
    {
        $values = [];
        $defaults = [];

        foreach (array_keys($this->editable()) as $key) {
            $values[$key] = $settings->get($key);
            $defaults[$key] = config('cg-cms.'.$key);
        }

        return Inertia::render('Settings/Index', [
            'values' => $values,
            'defaults' => $defaults,
            'overridden' => array_keys($settings->all()),
            'cache' => fn (): array => $this->cacheStats(),
        ]);
    }

    public function update(Request $request, Settings $settings, SeoFileWriter $files): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        $settings->put($validated);

        // The machine-readable files embed several of these directly:
        // robots.txt carries the sitemap URL built from the domain, llms.txt
        // opens with the site name and description. Leaving them until the
        // next content change would mean a settings save that appears to have
        // done nothing.
        $files->robots();
        $files->llms();

        return back()->with('success', 'Settings saved.');
    }

    /**
     * Empty the page cache.
     *
     * Deliberately the only cache control here. "Flush everything" is the one
     * an editor genuinely needs after changing something global, and it is
     * safe: the cache rebuilds on demand. Anything finer grained is what the
     * purge-on-save graph is for, and a button that lets somebody invalidate
     * by tag by hand is a button for debugging, not for editing.
     */
    public function flushCache(CachePurger $purger): RedirectResponse
    {
        $purger->flush();

        return back()->with('success', 'Page cache emptied. It rebuilds as pages are visited.');
    }

    public function rebuildFiles(SeoFileWriter $files): RedirectResponse
    {
        $result = $files->sitemap();
        $files->robots();
        $files->llms();
        $files->rss();

        return back()->with('success', sprintf(
            'Rebuilt: %d URLs in the sitemap, plus robots.txt, llms.txt and the feed.',
            $result['urls'],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function cacheStats(): array
    {
        $entries = PageCacheEntry::query();

        return [
            'pages' => (clone $entries)->count(),
            'bytes' => (int) (clone $entries)->sum('bytes'),
            'oldest' => (clone $entries)->min('written_at'),
        ];
    }
}
