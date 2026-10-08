<?php

declare(strict_types=1);

namespace Cg\Cms;

use Cg\Cms\Admin\AdminVite;
use Cg\Cms\Admin\EntryPayload;
use Cg\Cms\Admin\QueueMonitor;
use Cg\Cms\Cache\CacheContext;
use Cg\Cms\Cache\CachePurger;
use Cg\Cms\Cache\CollectionVersion;
use Cg\Cms\Cache\PageCache;
use Cg\Cms\Cdn\CdnPurger;
use Cg\Cms\Cdn\CloudflarePurger;
use Cg\Cms\Cdn\NullPurger;
use Cg\Cms\Console\AdminAssetsCommand;
use Cg\Cms\Console\Content;
use Cg\Cms\Console\ExportNginxRedirectsCommand;
use Cg\Cms\Console\FlushCacheCommand;
use Cg\Cms\Console\MakeBlockCommand;
use Cg\Cms\Console\MakeCollectionCommand;
use Cg\Cms\Console\PruneCacheCommand;
use Cg\Cms\Console\SeoAuditCommand;
use Cg\Cms\Console\SeoFilesCommand;
use Cg\Cms\Console\SyncSchemaCommand;
use Cg\Cms\Console\WarmCacheCommand;
use Cg\Cms\Content\EntryObserver;
use Cg\Cms\Content\TiptapRenderer;
use Cg\Cms\Http\Controllers\MediaVariantController;
use Cg\Cms\Http\Middleware\HandleAdminInertiaRequests;
use Cg\Cms\Lint\Linter;
use Cg\Cms\Lint\TextExtractor;
use Cg\Cms\Media\GdProcessor;
use Cg\Cms\Media\ImageProcessor;
use Cg\Cms\Media\MediaLibrary;
use Cg\Cms\Media\MediaResolver;
use Cg\Cms\Models\Entry;
use Cg\Cms\Models\Redirect;
use Cg\Cms\Redirects\NotFoundHandler;
use Cg\Cms\Redirects\RedirectResolver;
use Cg\Cms\Schema\BlockRegistry;
use Cg\Cms\Schema\Collection;
use Cg\Cms\Schema\CollectionRegistry;
use Cg\Cms\Seo\Breadcrumbs;
use Cg\Cms\Seo\Checks\InternalLinks;
use Cg\Cms\Seo\Checks\SeoChecks;
use Cg\Cms\Seo\JsonLd;
use Cg\Cms\Seo\SeoFileWriter;
use Cg\Cms\Seo\SeoResolver;
use Cg\Cms\Settings\Settings;
use Cg\Cms\View\Components\Blocks;
use Cg\Cms\View\Components\Image;
use Cg\Cms\View\Components\Seo;
use Closure;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CgCmsServiceProvider extends ServiceProvider
{
    /**
     * Extra schema registration callbacks.
     *
     * @var array<int, Closure(CollectionRegistry): void>
     */
    private static array $schemaCallbacks = [];

    /**
     * Register collections programmatically, from a host app's provider.
     *
     * @param  Closure(CollectionRegistry): void  $callback
     */
    public static function defineSchemaUsing(Closure $callback): void
    {
        self::$schemaCallbacks[] = $callback;
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cg-cms.php', 'cg-cms');

        $this->app->singleton(CollectionRegistry::class, function (): CollectionRegistry {
            $registry = new CollectionRegistry;

            // Definitions come from a PHP file rather than config, because
            // config:cache serialises with var_export() and cannot represent
            // objects. The path is a string, so it caches fine.
            $path = config('cg-cms.schema_path');

            if (is_string($path) && is_file($path)) {
                foreach ((array) require $path as $collection) {
                    if ($collection instanceof Collection) {
                        $registry->register($collection);
                    }
                }
            }

            // Programmatic registration, for anything that needs to be built at
            // runtime rather than declared in the schema file.
            foreach (self::$schemaCallbacks as $callback) {
                $callback($registry);
            }

            return $registry;
        });

        $this->app->singleton(TiptapRenderer::class, fn (): TiptapRenderer => new TiptapRenderer(
            siteDomain: config('cg-cms.site.domain'),
        ));
        $this->app->singleton(CollectionVersion::class);

        $this->app->singleton(TextExtractor::class);

        // One instance per request, so the settings table is read at most once
        // even though several services ask for it while building a page head.
        $this->app->singleton(Settings::class);

        // Rule set and severities come from config, so a site can retune the
        // brand voice without touching a rule.
        $this->app->singleton(Linter::class, fn ($app): Linter => new Linter(
            $app->make(TextExtractor::class),
            (array) config('cg-cms.lint', []),
        ));

        /*
         * The media pipeline. GD is the default because it is always there;
         * an app with libvips binds its own ImageProcessor and nothing else
         * changes, which is the reason it is an interface.
         */
        $this->app->singletonIf(ImageProcessor::class, GdProcessor::class);

        $this->app->singleton(MediaLibrary::class, fn ($app): MediaLibrary => new MediaLibrary(
            $app->make(ImageProcessor::class),
            (array) config('cg-cms.media', []),
        ));

        // Scoped, because it memoises rows: a singleton would serve a queue
        // worker the alt text an image had when the worker started.
        $this->app->scoped(MediaResolver::class);

        // Editor-time SEO gates. Thresholds and the publish-blocking list are
        // config, for the same reason the lint severities are.
        $this->app->singleton(SeoChecks::class, fn (): SeoChecks => new SeoChecks(
            (array) config('cg-cms.seo.checks', []),
        ));

        $this->app->singleton(InternalLinks::class, fn (): InternalLinks => new InternalLinks([
            (string) config('cg-cms.site.domain'),
            (string) config('app.url'),
        ]));

        // Built-ins plus anything the app registers. A container singleton
        // rather than a static, so nothing leaks between requests.
        $this->app->singleton(BlockRegistry::class);

        // Per-request, so tags from one render never leak into another.
        $this->app->scoped(CacheContext::class);

        $this->app->singleton(PageCache::class, fn (): PageCache => new PageCache(
            basePath: public_path((string) config('cg-cms.page_cache.path', 'page-cache')),
            enabled: (bool) config('cg-cms.page_cache.enabled', true),
            compress: (array) config('cg-cms.page_cache.compress', ['gzip']),
        ));

        $this->app->singleton(CachePurger::class, fn ($app): CachePurger => new CachePurger(
            $app->make(PageCache::class),
            $app->make(CollectionVersion::class),
        ));

        /*
         * The SEO services take one merged array rather than reading config()
         * themselves. Site identity and SEO defaults are two config blocks for
         * editing convenience and one concept at the point of use, and passing
         * the merged result in keeps all three classes pure enough to test
         * without booting an application.
         */
        $this->app->singleton(SeoResolver::class, fn ($app): SeoResolver => new SeoResolver(
            $this->seoConfig(),

            // A closure, not the instance. The resolver is request-scoped and
            // this is a singleton, so holding one would freeze its memo for
            // the life of a queue worker.
            fn (): MediaResolver => $app->make(MediaResolver::class),
        ));

        $this->app->singleton(Breadcrumbs::class, fn ($app): Breadcrumbs => new Breadcrumbs(
            $app->make(CollectionRegistry::class),
            $this->seoConfig(),
        ));

        $this->app->singleton(JsonLd::class, fn ($app): JsonLd => new JsonLd(
            $this->seoConfig(),
            $app->make(Breadcrumbs::class),
        ));

        /*
         * The CDN purger resolves to a null implementation unless a driver is
         * configured, so nothing calling it needs to know whether there is a
         * CDN in front of the origin. Misconfiguration falls back to null with
         * a log line rather than throwing: a missing token should not be able
         * to fail a content save.
         */
        $this->app->singleton(CdnPurger::class, function (): CdnPurger {
            if (config('cg-cms.cdn.driver') !== 'cloudflare') {
                return new NullPurger;
            }

            $zone = (string) config('cg-cms.cdn.cloudflare.zone_id');
            $token = (string) config('cg-cms.cdn.cloudflare.api_token');

            if ($zone === '' || $token === '') {
                Log::warning('CDN driver is cloudflare but zone_id or api_token is missing. Falling back to null.');

                return new NullPurger;
            }

            return new CloudflarePurger(
                zoneId: $zone,
                apiToken: $token,
                domain: (string) config('cg-cms.site.domain'),
            );
        });

        $this->app->singleton(SeoFileWriter::class, fn ($app): SeoFileWriter => new SeoFileWriter(
            $app->make(CollectionRegistry::class),
            $this->seoConfig(),
            public_path(),
        ));
    }

    /**
     * Mount the admin SPA at its configured prefix.
     *
     * Registered here rather than left to the host application, so installing
     * the package gives you a working admin without a routes file, a
     * middleware group or a Vite entry. The prefix, guard and middleware are
     * all config, and the whole thing can be switched off with one flag for an
     * application that wants the CMS without the admin.
     */
    private function registerAdmin(): void
    {
        if (! config('cg-cms.admin.enabled', true)) {
            return;
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'cgcms');

        Route::middleware([
            ...(array) config('cg-cms.admin.middleware', []),

            // Appended rather than configurable. Without it there is no
            // Inertia response and no package root view, which is not a
            // preference, it is the admin.
            HandleAdminInertiaRequests::class,
        ])
            ->prefix((string) config('cg-cms.admin.prefix', 'admin'))
            ->name('cgcms.admin.')
            ->group(__DIR__.'/../routes/admin.php');
    }

    /**
     * The public variant URL, for whatever nginx did not find on disk.
     *
     * Outside every middleware group on purpose: no session, no cookies, no
     * page cache. The constraints keep anything that is not a plausible
     * variant URL from reaching the controller at all.
     */
    private function registerMediaRoute(): void
    {
        $base = trim((string) config('cg-cms.media.public_path', 'media'), '/');

        Route::get($base.'/{uuid}/{preset}.{format}', MediaVariantController::class)
            ->where([
                'uuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
                'preset' => '[a-z0-9-]+',
                'format' => 'avif|webp|jpg|png',
            ])
            ->name('cgcms.media.variant');
    }

    /**
     * Site identity and SEO defaults, with any saved overrides applied.
     *
     * Delegated to the settings store rather than reading config directly, so
     * a value edited in the admin reaches SeoResolver, JsonLd and
     * SeoFileWriter without any of the three knowing settings exist.
     *
     * @return array<string, mixed>
     */
    private function seoConfig(): array
    {
        return $this->app->make(Settings::class)->seoConfig();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'cgcms');

        // Rich text arrives as a TipTap document whose text nodes carry their
        // own spacing: " then text" after a bold word starts with the space.
        // Laravel's TrimStrings would trim it and glue the words together on
        // every save. So a request carrying a document is not trimmed; the
        // plain text fields are trimmed by EntryPayload instead.
        TrimStrings::skipWhen(fn (Request $request): bool => EntryPayload::containsDocument($request->all()));

        // The worker's heartbeat, so the admin's queue screen can tell a
        // stopped worker from an empty queue.
        Queue::looping(fn () => $this->app->make(QueueMonitor::class)->beat());

        // Block views are overridable per app via resources/views/vendor/cgcms.
        Blade::component('cms-blocks', Blocks::class);

        // One tag for the whole head. See the note in the component about why
        // this is not a per-template partial.
        Blade::component('cms-seo', Seo::class);

        Blade::component('cms-image', Image::class);

        /*
         * An empty error bag, so `@error` and `$errors` work on pages that have
         * no session.
         *
         * Laravel shares `errors` from ShareErrorsFromSession, which only runs
         * in middleware groups that start a session. Public pages here
         * deliberately do not, so any template rendering a form would hit
         * "Undefined variable $errors" purely because the page is cacheable.
         *
         * Shared at boot, so the middleware still overrides it with the real
         * bag on routes that do have a session.
         */
        View::share('errors', new ViewErrorBag);

        Entry::observe(EntryObserver::class);

        // The redirect map is cached as one array, so it must be dropped
        // whenever a rule changes. Saving through the model is the only
        // supported path, and there is a test asserting the flush happens.
        Redirect::saved(fn () => RedirectResolver::flush());
        Redirect::deleted(fn () => RedirectResolver::flush());

        // Redirects resolve from the 404 path, so matched routes never pay for
        // the lookup. Hooked to the exception rather than a fallback route:
        // see the note in NotFoundHandler for why that difference matters.
        $this->callAfterResolving(ExceptionHandler::class, function ($handler): void {
            if (! method_exists($handler, 'renderable')) {
                return;
            }

            $handler->renderable(function (NotFoundHttpException $e, $request) {
                return $this->app->make(NotFoundHandler::class)->handle($request);
            });
        });

        $this->registerMediaRoute();

        $this->registerAdmin();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/cg-cms.php' => config_path('cg-cms.php'),
            ], 'cg-cms-config');

            /*
             * Built admin assets.
             *
             * The package commits dist/, so this copies already-compiled files
             * and the server never needs Node. That is not a convenience: on a
             * 1GB droplet, building a React bundle on the box is the OOM in
             * CLAUDE.md's pitfall list, and this is how it stays fixed.
             *
             * Run on every deploy, with --force, because the filenames carry
             * content hashes and the old ones are meaningless.
             */
            $this->publishes([
                __DIR__.'/../dist' => public_path(AdminVite::BUILD_DIRECTORY),
            ], 'cg-cms-assets');

            $this->commands([
                WarmCacheCommand::class,
                FlushCacheCommand::class,
                SyncSchemaCommand::class,
                SeoFilesCommand::class,
                ExportNginxRedirectsCommand::class,
                PruneCacheCommand::class,
                AdminAssetsCommand::class,
                SeoAuditCommand::class,
                MakeCollectionCommand::class,
                MakeBlockCommand::class,
                Content\CollectionsCommand::class,
                Content\EntriesCommand::class,
                Content\EntryCommand::class,
                Content\CreateEntryCommand::class,
                Content\UpdateEntryCommand::class,
                Content\CheckEntryCommand::class,
            ]);
        }

        /*
         * The nightly audit, registered by the package so installing it is
         * enough. The host only needs the scheduler's cron line, which it has
         * anyway for the queue. Null in config leaves scheduling to the host.
         */
        $schedule = config('cg-cms.seo.audit_schedule');

        if (is_string($schedule) && $schedule !== '') {
            $this->callAfterResolving(Schedule::class, function (Schedule $scheduler) use ($schedule): void {
                $scheduler->command(SeoAuditCommand::class)
                    ->cron($schedule)
                    ->withoutOverlapping()
                    ->onOneServer();
            });
        }

        // Daily, and not optional: it is what keeps cached forms inside their
        // token window (see PruneCacheCommand). Unscheduled, a form on a page
        // nobody edits for two months quietly stops accepting enquiries.
        $this->callAfterResolving(Schedule::class, function (Schedule $scheduler): void {
            $scheduler->command(PruneCacheCommand::class)
                ->dailyAt('04:15')
                ->withoutOverlapping()
                ->onOneServer();
        });
    }
}
