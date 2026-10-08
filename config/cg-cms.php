<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
|------------------------------------------------------------------------------
| IMPORTANT: this file must contain only scalars and plain arrays.
|------------------------------------------------------------------------------
|
| `php artisan config:cache` serialises config with var_export(), which cannot
| represent objects or closures. Collection and Field definitions are therefore
| NOT here: they live in the file named by `schema_path` below, loaded by the
| service provider at boot.
|
| Config caching is not optional on a 1GB box, so anything added here that
| var_export cannot handle will break production rather than fail loudly in
| development. There is a test asserting config:cache succeeds.
|
*/

return [

    'site' => [
        'name' => env('CG_SITE_NAME', 'Chris Garlick'),
        'domain' => env('CG_SITE_DOMAIN', 'https://chrisgarlick.com'),
        'locale' => 'en_GB',
        'contact_email' => env('CONTACT_EMAIL'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Schema
    |---------------------------------------------------------------------------
    |
    | A PHP file returning an array of Cg\Cms\Schema\Collection objects. A path
    | is a string, so it survives config caching; the definitions it returns do
    | not need to.
    |
    */

    'schema_path' => base_path('app/Cms/schema.php'),

    // The application's own blocks, alongside the built-in ones. Optional;
    // `php artisan cms:block` creates it.
    'blocks_path' => base_path('app/Cms/blocks.php'),

    // The colours a Columns block's column may wear (its tint select).
    'block_tints' => ['workflow', 'agents', 'data', 'engineering'],

    'table_prefix' => env('CG_TABLE_PREFIX', ''),

    /*
    |---------------------------------------------------------------------------
    | Generators
    |---------------------------------------------------------------------------
    |
    | Where `php artisan cms:collection` adds the routes for a new content
    | type. A site that keeps its cookie-free public pages in their own file
    | (routes/public.php) gets them there; anything else gets routes/web.php.
    | Templates come from stubs/cms/ in the application when it has them.
    |
    */

    /*
    | Who changes made through the cms:* content commands are attributed to,
    | by email. Usually a dedicated "Claude Code" user, so the admin's history
    | shows which edits came from the terminal.
    */
    'cli' => [
        'user' => env('CMS_CLI_USER'),
    ],

    'generator' => [
        'routes' => is_file(base_path('routes/public.php')) ? base_path('routes/public.php') : base_path('routes/web.php'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Admin
    |---------------------------------------------------------------------------
    |
    | An Inertia 2 and React SPA shipped inside this package: its own root view,
    | its own Vite build, its own page resolution. The host application needs no
    | Node and no config changes, only:
    |
    |     php artisan vendor:publish --tag=cg-cms-assets
    |
    | Sessions and CSRF are enabled here and nowhere else. The public site stays
    | cookie-free, which the page cache depends on.
    |
    */

    'admin' => [
        'enabled' => env('CG_ADMIN_ENABLED', true),

        // Changing this moves every admin URL. It is also shared with the
        // client, which builds its links from it rather than from a route
        // table shipped to the browser.
        'prefix' => env('CG_ADMIN_PREFIX', 'admin'),

        'guard' => env('CG_ADMIN_GUARD', 'web'),

        /*
        | Middleware for the admin group.
        |
        | Spelled out rather than reusing the host application's `web` group,
        | because an application is free to put anything in that group and the
        | admin needs exactly these: cookies, a session, validation errors and
        | CSRF. The package's Inertia middleware is appended by the provider.
        */
        'middleware' => [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            SubstituteBindings::class,
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | SEO
    |---------------------------------------------------------------------------
    |
    | Site-wide defaults and identity. These are the bottom rung of the fallback
    | chain: an entry's own SEO field wins, then its collection's template, then
    | what is here. Nothing a template renders is ever resolved twice.
    |
    */

    'seo' => [
        // {title} and {site} are substituted. Collections may override it.
        'title_template' => env('CG_SEO_TITLE_TEMPLATE', '{title} | {site}'),

        'description' => env('CG_SEO_DESCRIPTION'),

        // Relative paths are resolved against site.domain.
        'image' => env('CG_SEO_IMAGE'),
        'image_alt' => env('CG_SEO_IMAGE_ALT'),

        'twitter' => env('CG_SEO_TWITTER'),

        /*
        | Sitelinks search box.
        |
        | Left null on purpose. Declaring a SearchAction tells Google it may
        | offer a search box that submits here, and pointing one at a route the
        | site does not serve produces a box that 404s. Set this only once
        | there is a search page, e.g. '/search?q={search_term_string}'.
        */
        'search_url' => env('CG_SEO_SEARCH_URL'),

        'area_served' => env('CG_SEO_AREA_SERVED', 'United Kingdom'),
        'currency' => env('CG_SEO_CURRENCY', 'GBP'),

        'person' => [
            'name' => env('CG_SEO_PERSON_NAME', 'Chris Garlick'),
            'job_title' => env('CG_SEO_PERSON_TITLE'),
            'same_as' => array_values(array_filter([
                env('CG_SEO_LINKEDIN'),
                env('CG_SEO_GITHUB'),
            ])),
        ],

        'organization' => [
            'name' => env('CG_SEO_ORG_NAME'),
            'logo' => env('CG_SEO_ORG_LOGO'),
            'same_as' => array_values(array_filter([
                env('CG_SEO_LINKEDIN'),
            ])),
        ],

        /*
        | Files written to public/ and served by nginx with no PHP involved.
        |
        | Paths are relative to public_path(). The sitemap index lives at the
        | root because that is where crawlers and robots.txt expect it.
        */
        'files' => [
            'sitemap' => 'sitemap.xml',
            'robots' => 'robots.txt',
            'llms' => 'llms.txt',
            'rss' => 'article/rss.xml',
        ],

        // Sitemaps chunk at 50,000 URLs, which is the protocol limit.
        'sitemap_chunk' => 50000,

        // Which collection the feed publishes, and how many items.
        'rss_collection' => env('CG_SEO_RSS_COLLECTION', 'article'),
        'rss_limit' => (int) env('CG_SEO_RSS_LIMIT', 20),

        // Rebuild the sitemap, llms.txt and the feed after a content change.
        // Queued and debounced to one run a minute. See RegenerateSeoFiles.
        'regenerate_on_publish' => env('CG_SEO_REGENERATE', true),

        // Paths kept out of the sitemap and out of llms.txt. Ported from the
        // filter in the current astro.config.mjs.
        'exclude' => [
            'admin',
            'admin/*',
            'api/*',
            'preview/*',
            'thanks',
            'thanks/*',
        ],

        /*
        | Editor-time checks, and the nightly audit.
        |
        | Every check warns in the editor. The ones named in block_on_publish
        | also stop an entry being published, and only published: a draft is
        | allowed to be unfinished, and refusing to save one is how people
        | end up keeping their drafts somewhere else.
        */
        'checks' => [
            'enabled' => env('CG_SEO_CHECKS', true),

            'block_on_publish' => ['image-alt', 'multiple-h1'],

            'title' => ['min' => 30, 'max' => 60],
            'description' => ['min' => 70, 'max' => 155],
            'min_internal_links' => 2,

            // Words scanned for the keyword-in-opening check.
            'opening_words' => 100,
        ],

        // When cms:seo-audit runs, as cron. Null leaves it to the host app.
        'audit_schedule' => env('CG_SEO_AUDIT_SCHEDULE', '30 3 * * *'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Page cache
    |---------------------------------------------------------------------------
    |
    | Rendered HTML is written to disk so nginx can serve it with try_files and
    | never start PHP. Invalidation is tag-driven: every entry touched during a
    | render is recorded against the URL, so publishing purges exactly the
    | affected pages instead of flushing everything.
    |
    */

    'page_cache' => [
        'enabled' => env('CG_PAGE_CACHE_ENABLED', true),

        // Relative to public_path(). nginx serves from here directly.
        'path' => env('CG_PAGE_CACHE_PATH', 'page-cache'),

        // Precompress on write so nginx can use gzip_static / brotli_static.
        'compress' => ['gzip'],

        // Never cache these, even on GET.
        'except' => [
            'admin',
            'admin/*',
            'api/*',
            'preview/*',
            '_debugbar/*',
        ],

        // Re-render purged URLs. Keep concurrency at 1 on a single vCPU:
        // warming must never compete with live traffic.
        'warm_after_purge' => env('CG_PAGE_CACHE_WARM', true),

        // cms:prune-cache evicts and re-warms pages older than this, daily,
        // so a cached form never outlives its 30-day FormToken window.
        'max_age_days' => (int) env('CG_PAGE_CACHE_MAX_AGE_DAYS', 14),
        'warm_concurrency' => (int) env('CG_PAGE_CACHE_WARM_CONCURRENCY', 1),
    ],

    /*
    |---------------------------------------------------------------------------
    | Media
    |---------------------------------------------------------------------------
    |
    | Originals live on a private disk and are never served. What the public
    | sees is a variant at /media/{uuid}/{preset}.{ext}, written to public/ the
    | first time it is generated so nginx serves every request after that with
    | no PHP involved.
    |
    | Variants for every preset are generated on the queue straight after an
    | upload. The in-request path exists for a preset added after the image
    | was uploaded, not as the normal route.
    |
    */

    'media' => [
        // A disk from config/filesystems.php. Must not be a public one: an
        // original can be a 30MB phone photo with GPS in its EXIF.
        'disk' => env('CG_MEDIA_DISK', 'local'),
        'directory' => 'cms-media',

        // Relative to public_path(), and the first segment of every URL.
        // Read when routes register, so it is env rather than runtime config.
        'public_path' => env('CG_MEDIA_PATH', 'media'),

        // Kilobytes. Kept well under the 1GB box's PHP memory, because GD
        // decodes a whole image into memory at four bytes per pixel.
        'max_upload_kb' => (int) env('CG_MEDIA_MAX_KB', 20480),

        // Longest edge. Larger originals are scaled down on the queue before
        // anything else reads them: a 6000px original costs 144MB to decode.
        'max_dimension' => 4000,

        // No SVG. An SVG is a document that can carry script, and serving one
        // from the site's own origin is a stored XSS waiting for an upload.
        'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],

        // Modern formats emitted as <picture> sources, best first. Only those
        // the image driver can actually encode are used, see ImageProcessor.
        'formats' => ['avif', 'webp'],

        'quality' => 80,

        // width x height, cropped to fill around the focal point. A preset
        // with a null height keeps the aspect ratio and only bounds width.
        'presets' => [
            'article-hero' => ['width' => 1200, 'height' => 630],
            'card' => ['width' => 600, 'height' => 400],
            'og' => ['width' => 1200, 'height' => 630],
            'thumb' => ['width' => 200, 'height' => 200],
            'full' => ['width' => 1600, 'height' => null],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | CDN
    |---------------------------------------------------------------------------
    */

    'cdn' => [
        'driver' => env('CG_CDN_DRIVER', 'null'), // null | cloudflare
        'cloudflare' => [
            'zone_id' => env('CLOUDFLARE_ZONE_ID'),
            'api_token' => env('CLOUDFLARE_API_TOKEN'),
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Content lint
    |---------------------------------------------------------------------------
    |
    | Brand voice rules enforced at save time. These are the rules from
    | CLAUDE.md, encoded so they cannot be forgotten. 'block' rules fail
    | validation; 'warn' rules surface in the editor but still save.
    |
    */

    'lint' => [
        'enabled' => env('CG_LINT_ENABLED', true),

        'block' => ['no-em-dash', 'no-html-entities'],
        'warn' => ['no-filler-words', 'uk-spelling', 'en-dash-ranges'],

        // Words that describe work without naming any of it. Warnings with a
        // count, because one is a word choice and six is a voice problem.
        'filler_words' => [
            'leverage', 'synergy', 'seamless', 'robust', 'cutting-edge',
            'end-to-end', 'best-in-class', 'transformative', 'thought leadership',
            'stakeholders', 'utilise', 'utilize', 'holistic', 'bespoke solutions',
            'game-changing', 'unlock', 'empower', 'revolutionise', 'revolutionize',
        ],

        // Whole words, US => UK. Inflections are listed rather than derived:
        // the British form of "centered" is "centred", not "centreed".
        //
        // "Program" is absent on purpose. British English keeps it for software
        // and reserves "programme" for broadcasts and schedules, so flagging it
        // here would be wrong on almost every article this site publishes.
        'spellings' => [
            'color' => 'colour',
            'colors' => 'colours',
            'colored' => 'coloured',
            'organization' => 'organisation',
            'organizations' => 'organisations',
            'organize' => 'organise',
            'organized' => 'organised',
            'optimize' => 'optimise',
            'optimized' => 'optimised',
            'optimizing' => 'optimising',
            'optimization' => 'optimisation',
            'behavior' => 'behaviour',
            'behaviors' => 'behaviours',
            'analyze' => 'analyse',
            'analyzed' => 'analysed',
            'analyzing' => 'analysing',
            'center' => 'centre',
            'centers' => 'centres',
            'centered' => 'centred',
            'defense' => 'defence',
            'favorite' => 'favourite',
            'favorites' => 'favourites',
            'catalog' => 'catalogue',
            'recognize' => 'recognise',
            'recognized' => 'recognised',
            'realize' => 'realise',
            'realized' => 'realised',
            'customize' => 'customise',
            'customized' => 'customised',
            'customization' => 'customisation',
            'prioritize' => 'prioritise',
            'prioritized' => 'prioritised',
            'summarize' => 'summarise',
            'specialize' => 'specialise',
            'specialized' => 'specialised',
            'minimize' => 'minimise',
            'maximize' => 'maximise',
            'apologize' => 'apologise',
            'traveled' => 'travelled',
            'modeling' => 'modelling',
            'modeled' => 'modelled',
            'canceled' => 'cancelled',
            'labeled' => 'labelled',
            'fulfill' => 'fulfil',
            'fulfillment' => 'fulfilment',
            'enrollment' => 'enrolment',
            'gray' => 'grey',
        ],
    ],

];
