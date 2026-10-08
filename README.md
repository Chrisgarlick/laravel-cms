# cg-cms

A lightweight, SEO-first CMS for Laravel. Content in `jsonb`, HTML on disk, nginx does the
serving.

Built to run a real site on a 1 vCPU / 1GB droplet without the origin ever being the
bottleneck. Full design rationale, the phase roadmap and the build log for each phase live in
`laravel_cms_plan.md` in the application repo (`~/Herd/portfolio`).

## The idea in one paragraph

Content lives in a single `entries` table as `jsonb`, so adding a field needs no migration.
Rich text is rendered to HTML once, on save, and stored alongside the source, so serving a page
parses nothing. Rendered pages are written to disk where nginx serves them with `try_files`
and PHP never starts. Publishing purges exactly the URLs that depended on what changed,
resolved from a dependency graph recorded at render time. There is no build step and no
rebuild service.

## Status

Phase 3, step 3 of 6. Built: storage, render-on-save, page cache with precise invalidation,
blocks, revisions, redirects with slug history, forms with a stateless signed token, the SEO
module (meta, JSON-LD, sitemap, robots.txt, llms.txt, RSS), brand-voice lint, Cloudflare
purging, the admin's Inertia boundary, the field registry and entry editor, and the block
canvas with repeaters.

Not built yet: the collection index proper, the media library, roles, and the media pipeline
behind `media` fields.

The Phase 0 benchmark gate is still open: the numbers in the build log are from a laptop, and
the plan requires them from a 1GB LON1 droplet with the nginx `try_files` rule in place.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- **Postgres.** Not optional: the storage design uses `jsonb`, a `jsonb_path_ops` GIN index
  and generated columns.

## Install

```bash
composer config repositories.cg-cms path ../../dev/cg-cms
composer require chrisgarlick/cg-cms:@dev
php artisan migrate
php artisan vendor:publish --tag=cg-cms-assets --force
```

The last line copies the pre-built admin bundle into `public/vendor/cg-cms/`. **The host app
needs no Node at all:** no `npm install`, no Vite plugin, no config change. `dist/` is
committed for exactly this reason, because building a React bundle on a 1GB droplet is how you
run out of memory mid-deploy. Run it on every deploy: the filenames carry content hashes.

## Admin

An Inertia 2 and React SPA at `/admin`, shipped inside the package with its own root view, its
own Vite build and its own page resolution. It shares nothing with the host application's
front end, so an app that uses Inertia itself keeps its own setup untouched.

Sessions and CSRF are enabled on the admin prefix and nowhere else. The public site stays
cookie-free, which the page cache depends on, and there is a test asserting no route outside
the admin prefix starts a session.

```bash
# Working on the admin itself, from the package directory:
npm run dev      # HMR on :5174, independent of the site's own Vite
npm run check    # tsc --noEmit
npm run smoke    # build the real editor in jsdom and exercise it
npm run build    # rebuild dist/ before committing
npm run verify   # check, then smoke, then build

# From the application:
php artisan cms:admin-assets           # is the published bundle current?
php artisan cms:admin-assets --check   # same, but non-zero exit. For CI
```

`cms:admin-assets` compares a content hash of `resources/js/admin` against one recorded in
`dist/.sources` at build time, and the published manifest against `dist/`. Hashes rather than
timestamps, because git does not preserve mtimes and a fresh clone would otherwise look stale.
`--publish` empties the published directory first, since every build emits new
content-hashed filenames and `vendor:publish` removes nothing.

`npm run smoke` is worth running before any release. It constructs the editor in jsdom from
the same extension configuration the component uses, which catches the failures that make the
admin unusable rather than merely wrong: an extension conflict, a missing command, an editor
that will not build. It exists because a duplicate extension and an unguarded use of a
destroyed editor both passed `tsc`, passed the build, passed 193 PHP tests, and rendered a
blank admin.

Visit `/admin/boundary` on a running install to see what the boundary resolved to: which
manifest was read, which base assets load from, and whether a dev server is driving the page.

### The field registry

Every field type maps to one React component, resolved by its type string. The server
serialises the schema into the page props and the client renders it, so there is one editor
screen for every collection and adding a collection needs no admin change at all.

Adding a field type is one PHP class plus one component:

```php
Field::colour('accent')->label('Accent colour')->help('Used on the hero.');
```

```tsx
import { registerField } from 'cg-cms/fields/registry'

registerField('colour', ColourField)
```

The editor autosaves to a draft revision every fifteen seconds, never to the live row, and
its lint and search-result panels are rendered from the server's own `Linter` and
`SeoResolver` rather than a second implementation in TypeScript.

### Blocks and repeaters

`Field::blocks()` is flexible content: an ordered list of typed sections, chosen from the set
the field allows, edited on a drag-and-drop canvas. `Field::repeater()` is a repeating group
of fields inside one field. They share a list component, because they are the same problem.

```php
Field::blocks('content')->allow(Block::builtIns()),

Field::repeater('items')
    ->label('Questions')
    ->rowLabel('question')   // what a collapsed row shows
    ->min(1)
    ->fields([
        Field::text('question')->required()->maxLength(200),
        Field::richText('answer')->lint(['no-em-dash']),
    ]),
```

Rows render their subfields through the same registry as a top-level field, so every field
type works nested, rich text included. Rich text is pre-rendered on save at **any** depth, and
stored mirroring the data path: an answer at `content.0.items.2.answer` in `data` has its HTML
at the same path in `rendered`.

Unlike ACF, layouts are declared in PHP rather than built through a UI. A block type is a
class plus a Blade view, so it is reviewable, diffable and deployed with the code.

Block content is validated per item against whichever type it declares, so an unknown block
type or a missing required subfield is rejected on save rather than rendering as a silently
absent section.

## Defining content

```php
Collection::make('article')
    ->route('/article/{slug}')
    ->indexRoute('/article')
    ->orderBy('published_at', 'desc')
    ->fields([
        Field::text('title')->required()->maxLength(120),
        Field::slug('slug')->from('title'),
        Field::richText('body')->lint(['no-em-dash', 'no-html-entities']),
        Field::textarea('excerpt')->maxLength(300),
        Field::datetime('published_at')->nullable(),
    ]);
```

`title`, `slug`, `status`, `published_at` and `sort_order` are first-class columns because every
collection queries them. Everything else is a `jsonb` key. Fields you filter or sort on get
`->promote([...])`, which `php artisan cms:sync-schema` turns into a Postgres generated column
plus an index, via a migration you review before running.

## Caching

Public routes must run without sessions or cookies. A response carrying `Set-Cookie` is
per-visitor and cannot go in a shared cache, and its presence also stops a CDN caching the
HTML. Register them outside Laravel's `web` group:

```php
// bootstrap/app.php
->withRouting(
    web: __DIR__.'/../routes/web.php',      // sessions: /admin
    then: fn () => Route::middleware('public')
        ->group(base_path('routes/public.php')),
)
```

Then declare dependencies while rendering, so invalidation can be precise:

```php
$this->cacheContext->registerEntry($article);      // depends on this entry
$this->cacheContext->registerCollection('article'); // depends on the collection
```

Depend on the narrowest thing that is true. Invalidate the widest thing that might be affected.
A page that registers nothing is never cached, because it could not be invalidated later.

## Commands

| Command | Does |
|---|---|
| `cms:warm` | Pre-render pages into the cache. Run after deploy |
| `cms:flush-cache` | Delete every cached page, reset version stamps |
| `cms:prune-cache` | Sweep cached files with no dependency row, and rows with no file |
| `cms:sync-schema` | Generate a migration for promoted fields. `--write` to emit it |
| `cms:seo-files` | Write sitemap.xml, robots.txt, llms.txt and the feed. Aliased `cms:sitemap` |
| `cms:export-nginx-redirects` | Export redirects as an nginx `map`. An accelerator, not a dependency |

## Brand voice lint

The rules in `CLAUDE.md`, enforced on save rather than remembered. Em-dashes, double-hyphen
substitutes and HTML entities are rejected; filler words, US spellings and en-dash ranges are
warnings that still save. Code blocks and inline code are exempt, because `--write` is a flag
and not a punctuation mistake.

Enforcement lives in the entry observer, so it holds for the admin, a seeder, an Artisan
command and a tinker session alike. The importer opts out for one call:

```php
Linter::without(fn () => $this->import($legacyEntries));
```

## SEO

One component renders the whole head, so no page can forget a tag:

```blade
<x-cms-seo :entry="$article" />
<x-cms-seo title="Writing" description="Notes on AI and building software" />
```

Title, description and image resolve through a fallback chain: the entry's own SEO field, then
the collection's template, then the site default. Collections declare their part of it:

```php
Collection::make('article')
    ->label('Writing')
    ->seoTitle('{title} | {site}')
    ->seoDescriptionFrom('excerpt')
    ->seoImageFrom('featured_image')
    ->schemaOrg(ArticleBuilder::class);
```

`seoImageFrom` is named rather than discovered on purpose. A collection that stores an image it
is not allowed to publish (work under NDA) simply does not name one.

JSON-LD is emitted as a single `@graph` with linked `@id` values, encoded with `JSON_HEX_TAG`
so no authored value can close the script element.

## Testing

Requires Postgres. sqlite lacks `jsonb` and generated columns, so testing against it would
leave the storage design untested where it matters most.
