# kit's sections as cg-cms blocks

kit (`~/dev/kit`) writes its website sections as cg-cms block types with
`kit build-cgcms -out <folder>`: `kit-blocks.php` (the `Block[]`), a view per
block (`kit-blocks::<name>`), the compiled sections, their schemas and CSS, and
kit's runtime (`kit/kit.php`, `kit/cgcms.php`). Nothing in cg-cms is kit
specific; this is what cg-cms gives them and what an app sets up.

## What cg-cms provides

| For | In cg-cms |
|---|---|
| Typed defaults | `Field::default()` takes a string, number, boolean, list or null. `defaultValue()` and `toArray()` cast it to the field's type, and the admin seeds new blocks, rows and groups from `fields/defaults.ts`, so a number starts as a number and a toggle as `true`/`false`. |
| Number ranges | `Field::number()->min(0)->max(100)` validates `min:`/`max:` and sets the input's range. On a repeater, `min`/`max` still count rows. |
| Site details | `cg-cms.site_schema` points at a JSON schema of fields (kit's `<folder>/kit/site.schema.json`). The Settings screen shows them under **Site details**, with the block field types (a list is a repeater, an image a media field), validated by the same rules, and stores each as the setting `site.<field>`. A field cg-cms already has (`name` = `site.name`) stays under **Site**. `Settings::site()` returns them all by name. |
| Cache purge | `Settings::SITE_TAG` (`settings:site`) is the tag of a render that showed site details; kit's blocks with bound fields register it through `CacheContext::registerTag()`. Saving settings calls `CachePurger::purgeTags([Settings::SITE_TAG])`, which purges exactly those pages (disk, CDN, warming as for an entry). |
| Image sizes | Media presets named as kit's `sizes`: `hero` (1600 wide), `half` (1200), `product` (1200), width only since each section crops with CSS. Kit's `card` images use the existing `card` preset (600 × 400, cropped). |
| Previews | `cgcms::preview-styles` prints `cg-cms.preview_styles` (a list of stylesheet URLs) then `@stack('kit-styles')`. `BlockPreview` renders the blocks and the document as one Blade render, so what blocks push is still there when the head prints the stack. The package's own preview document includes it. |
| Static assets | `php artisan cms:kit-assets [<folder>] [--to=kit-blocks]` copies only `kit/*.css`, `kit/fonts/*` (woff2, woff, ttf, otf) and `sections/*/style.css` to `public/<to>/`, same relative paths, emptying it first. It refuses a target holding anything else. The PHP and JSON never reach `public/`; nginx serves the CSS and fonts as static files. |

## Setting up an app

1. Generate the folder: `kit build-cgcms -out kit-blocks` (from the app root, or
   `-out <app>/kit-blocks`). Commit it; `kit build-cgcms -check` in CI.
2. Blocks: in `app/Cms/blocks.php`,
   `return [...require base_path('kit-blocks/kit-blocks.php'), /* the app's own */];`,
   and list the `kit-…` handles a page's `Field::blocks()->allow([...])` wants.
3. Config (`config/cg-cms.php` in the app, or env):
   - `'site_schema' => base_path('kit-blocks/kit/site.schema.json')` (or `CG_SITE_SCHEMA`);
   - `'preview_styles' => ['/kit-blocks/kit/kit.css']`.
   kit's own keys, in `config/kit-blocks.php`: `url` (default `/kit-blocks`), and
   optionally `site` for details not set in the admin (cg-cms's settings win).
4. Assets: `php artisan cms:kit-assets kit-blocks` after every `kit build-cgcms`
   (in `make sync`), and on deploy unless `public/kit-blocks` is committed.
5. Layout: in the public layout's `<head>`, kit.css then the stack:
   `<link rel="stylesheet" href="/kit-blocks/kit/kit.css">` and `@stack('kit-styles')`.
   The stack must print after the blocks render: a page that `@extends` the
   layout does (the child renders first); a layout `@include`d at the top does not.
6. Previews: in the app's `resources/views/cms/block-preview.blade.php`, add
   `@include('cgcms::preview-styles')` to the `<head>`.
7. Site details: fill them in under Settings > Site details. Header, footer and
   call-to-action fields left empty show them.
