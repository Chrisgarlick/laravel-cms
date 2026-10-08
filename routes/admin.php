<?php

declare(strict_types=1);

use Cg\Cms\Http\Controllers\Admin\BlockPreviewController;
use Cg\Cms\Http\Controllers\Admin\BoundaryController;
use Cg\Cms\Http\Controllers\Admin\DashboardController;
use Cg\Cms\Http\Controllers\Admin\DraftController;
use Cg\Cms\Http\Controllers\Admin\EntryController;
use Cg\Cms\Http\Controllers\Admin\MediaController;
use Cg\Cms\Http\Controllers\Admin\QueueController;
use Cg\Cms\Http\Controllers\Admin\RedirectController;
use Cg\Cms\Http\Controllers\Admin\SchemaController;
use Cg\Cms\Http\Controllers\Admin\SearchController;
use Cg\Cms\Http\Controllers\Admin\SeoController;
use Cg\Cms\Http\Controllers\Admin\SessionController;
use Cg\Cms\Http\Controllers\Admin\SettingsController;
use Cg\Cms\Http\Controllers\Admin\SubmissionController;
use Cg\Cms\Http\Middleware\AuthenticateAdmin;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Admin routes
|------------------------------------------------------------------------------
|
| Loaded by the service provider inside a group carrying sessions, CSRF and the
| package's own Inertia middleware. The prefix is configurable, so these are
| defined relative to it and never hardcode `/admin`.
|
| These are the only routes in the package that start a session. The public
| routes in the host application deliberately do not, because a response
| carrying Set-Cookie cannot be written to the page cache or cached at the edge.
| Keeping the two sets apart is what makes both true at once: the nginx
| page-cache bypass keys on the session cookie, so an authenticated admin
| request can never be served a cached page and an admin response can never be
| written into the cache.
|
*/

Route::get('/signin', [SessionController::class, 'create'])->name('signin');
Route::post('/signin', [SessionController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('signin.store');

Route::middleware(AuthenticateAdmin::class)->group(function (): void {
    Route::post('/signout', [SessionController::class, 'destroy'])->name('signout');

    Route::get('/', DashboardController::class)->name('dashboard');

    // Reports what the package boundary resolved to on the running system.
    // Reaching it at all proves a lazily imported chunk loaded from the
    // published base, which is the failure a single-screen admin hides.
    Route::get('/boundary', BoundaryController::class)->name('boundary');

    // The command palette's search. JSON, fetched as you type.
    Route::get('/search', SearchController::class)
        ->middleware('throttle:240,1')
        ->name('search');

    // Unsaved blocks rendered by their real templates, for the editor.
    Route::post('/blocks/preview', BlockPreviewController::class)
        ->middleware('throttle:240,1')
        ->name('blocks.preview');

    // The content model, read only. Editing it is a change to the schema file.
    Route::get('/schema', SchemaController::class)->name('schema');

    /*
    | Redirects, submissions, media, SEO and settings.
    |
    | Registered before the {collection} group below, because that group's
    | prefix is a wildcard and would otherwise swallow /admin/redirects as a
    | collection handle called "redirects".
    */
    Route::prefix('/redirects')->name('redirects.')->group(function (): void {
        Route::get('/', [RedirectController::class, 'index'])->name('index');
        Route::post('/', [RedirectController::class, 'store'])->name('store');
        Route::get('/export', [RedirectController::class, 'export'])->name('export');
        Route::post('/import', [RedirectController::class, 'import'])->name('import');
        Route::put('/{redirect}', [RedirectController::class, 'update'])->name('update');
        Route::delete('/{redirect}', [RedirectController::class, 'destroy'])->name('destroy');

        Route::post('/not-found/{notFound}', [RedirectController::class, 'resolveNotFound'])
            ->name('not-found.resolve');
        Route::delete('/not-found/{notFound}', [RedirectController::class, 'forgetNotFound'])
            ->name('not-found.forget');
    });

    Route::prefix('/submissions')->name('submissions.')->group(function (): void {
        Route::get('/', [SubmissionController::class, 'index'])->name('index');
        Route::get('/export', [SubmissionController::class, 'export'])->name('export');
        Route::delete('/{submission}', [SubmissionController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('/media')->name('media.')->group(function (): void {
        Route::get('/', [MediaController::class, 'index'])->name('index');
        Route::get('/browse', [MediaController::class, 'browse'])->name('browse');

        // Throttled per user rather than globally. A folder of forty images
        // is forty requests in a few seconds and must not trip it; a script
        // looping on the endpoint should.
        Route::post('/', [MediaController::class, 'store'])
            ->middleware('throttle:120,1')
            ->name('store');
        Route::put('/{media}', [MediaController::class, 'update'])->name('update');
        Route::delete('/{media}', [MediaController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('/seo')->name('seo.')->group(function (): void {
        Route::get('/', [SeoController::class, 'index'])->name('index');
        Route::post('/audit', [SeoController::class, 'audit'])->name('audit');
    });

    Route::prefix('/queue')->name('queue.')->group(function (): void {
        Route::get('/', [QueueController::class, 'index'])->name('index');
        Route::post('/retry-all', [QueueController::class, 'retryAll'])->name('retry-all');
        Route::post('/failed/{uuid}/retry', [QueueController::class, 'retry'])->name('retry');
        Route::delete('/failed/{uuid}', [QueueController::class, 'forget'])->name('forget');
        Route::delete('/jobs/{id}', [QueueController::class, 'cancel'])->whereNumber('id')->name('cancel');
    });

    Route::prefix('/settings')->name('settings.')->group(function (): void {
        Route::get('/', [SettingsController::class, 'index'])->name('index');
        Route::put('/', [SettingsController::class, 'update'])->name('update');
        Route::post('/flush-cache', [SettingsController::class, 'flushCache'])->name('flush-cache');
        Route::post('/rebuild-files', [SettingsController::class, 'rebuildFiles'])->name('rebuild-files');
    });

    /*
    | Entries.
    |
    | One set of routes for every collection, because everything that differs
    | between collections is declared in the schema. `{collection}` is a
    | handle, not a model: an unknown one 404s in the controller rather than
    | being constrained here, so the error says which handle was wrong.
    |
    | Registered last so that a collection handle can never shadow a named
    | admin screen. `/admin/boundary` is the boundary check even if somebody
    | adds a collection called `boundary`.
    */
    Route::prefix('/{collection}')->name('entries.')->group(function (): void {
        Route::get('/', [EntryController::class, 'index'])->name('index');
        Route::get('/new', [EntryController::class, 'create'])->name('create');
        Route::post('/', [EntryController::class, 'store'])->name('store');
        Route::post('/bulk', [EntryController::class, 'bulk'])->name('bulk');

        // Not cached, not indexed, and JSON rather than Inertia: replacing
        // page props every fifteen seconds would fight the form the editor is
        // typing into.
        Route::post('/preview', [DraftController::class, 'preview'])->name('preview');

        Route::get('/{entry}/edit', [EntryController::class, 'edit'])->name('edit');
        Route::put('/{entry}', [EntryController::class, 'update'])->name('update');
        Route::delete('/{entry}', [EntryController::class, 'destroy'])->name('destroy');
        Route::post('/{entry}/autosave', [DraftController::class, 'store'])->name('autosave');
        Route::delete('/{entry}/draft', [EntryController::class, 'discardDraft'])->name('draft.discard');
        Route::post('/{entry}/revisions/{revision}/restore', [EntryController::class, 'restore'])
            ->name('revisions.restore');
    });
});
