<?php

declare(strict_types=1);

namespace Cg\Cms\Http\Middleware;

use Cg\Cms\Admin\AdminVite;
use Cg\Cms\Admin\NavCounts;
use Cg\Cms\Schema\Collection as CollectionSchema;
use Cg\Cms\Schema\CollectionRegistry;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * The package's own Inertia middleware.
 *
 * A host application that already uses Inertia has its own middleware, its own
 * root view and its own shared props, and the admin must not inherit any of
 * them. Registering this one on the admin route group only is what makes two
 * Inertia apps on one domain work: each request is handled by whichever
 * middleware its prefix put in the stack.
 */
final class HandleAdminInertiaRequests extends Middleware
{
    /**
     * The package's root view, not the application's.
     *
     * This single line is the difference between the admin rendering inside the
     * package's own HTML shell and rendering inside whatever the host app calls
     * `app.blade.php`.
     */
    protected $rootView = 'cgcms::admin';

    /**
     * Asset version, from the built manifest.
     *
     * When this changes, Inertia makes the client hard-reload rather than
     * continue talking to a bundle that no longer matches the server. Without
     * it, an admin tab left open across a deploy keeps posting props in the old
     * shape, and the failures look random.
     */
    public function version(Request $request): ?string
    {
        return app(AdminVite::class)->version();
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),

            'cms' => [
                'site' => (string) config('cg-cms.site.name'),
                'prefix' => '/'.ltrim((string) config('cg-cms.admin.prefix', 'admin'), '/'),
                'version' => (string) $this->version($request),

                // The navigation, from the schema. A hand-maintained menu is
                // a menu that forgets the collection somebody added last week.
                'collections' => array_values(array_map(
                    fn (CollectionSchema $collection): array => [
                        'handle' => $collection->handle,
                        'label' => $collection->labelText(),
                    ],
                    app(CollectionRegistry::class)->all(),
                )),

                // What the uploader needs on any screen that can open the
                // media picker, which is any screen with a media field.
                'media' => [
                    'maxKb' => (int) config('cg-cms.media.max_upload_kb'),
                    'accept' => array_values((array) config('cg-cms.media.mimes')),
                ],
            ],

            // Sidebar counts and attention dots. A closure so partial reloads
            // skip the queries, and only computed for a signed-in user.
            'nav' => fn (): ?array => $user === null ? null : app(NavCounts::class)->all(),

            'auth' => [
                // Only what a screen actually renders. Sharing the whole model
                // means every prop change on the user table silently becomes
                // part of the admin's public API, and it is how password
                // hashes end up in page props.
                'user' => $user === null ? null : [
                    'id' => $user->getAuthIdentifier(),
                    'name' => $user->name ?? '',
                    'email' => $user->email ?? '',
                ],
            ],

            // Closures, so the session is only read when a response is
            // actually being built for a request that has one.
            'flash' => [
                'success' => fn (): ?string => $request->session()->get('success'),
                'error' => fn (): ?string => $request->session()->get('error'),
            ],
        ];
    }
}
