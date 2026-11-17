# kit sections in cg-cms

**Status (11 November 2026): planned, nothing built.** kit's website sections (header, hero,
services, split, pricing… sixteen of them, the shop next) are written once as a Blade template
with a JSON content schema, in `~/dev/kit/sections/<name>/`. They already run as plain PHP and as
WordPress blocks (`~/dev/wp-base`). This plan makes each one a cg-cms block type, generated from
the same schema, so a cg-cms site and a WordPress site are built from the same sections and look
the same. Background: `~/dev/kit/plans/sections.md` (the sections) and
`~/dev/kit/plans/wordpress.md` (the schema format, section 1).

## Why

- cg-cms's 12 built-in blocks render placeholder markup ("Phase 4 ports the real design") and
  nothing on the front end uses kit yet. kit's sections are that real design, already accessible,
  responsive and tested.
- One schema per section means the WordPress editor, the cg-cms editor and kit's own checks agree
  on what a section's content is. A change to a section reaches both CMSs from one place.
- The redesign under way can pick sections from kit rather than design and build blocks
  one at a time.

## What gets built

### 1. `kit build-cgcms` (in kit's CLI)

Like `kit build-wp`, it writes everything a cg-cms app needs, into the app:

| Written | What it is |
|---|---|
| `app/Cms/kit-blocks.php` | Returns a `Block[]`: one `Block::make('kit-<name>', '<Title>')` per section, its fields mapped from the schema (below), `->view('kit.blocks.<name>')`. Generated; never edited. |
| `resources/views/kit/sections/<name>.blade.php` | The section's Blade template, exactly as in kit. |
| `resources/views/kit/blocks/<name>.blade.php` | A two-line wrapper the block renders: it turns cg-cms's `$data` into the section's `content` (below) and includes the section. |
| `public/kit/kit.css`, `public/kit/sections/<name>.css` | kit's tokens and the components the sections use (from their `uses`), and each section's CSS. |
| `app/Cms/kit/kit.php` | kit's runtime helpers (`kit_e`, `kit_url`, `kit_rich`, `kit_icon`…), loaded by a service provider. |

`app/Cms/blocks.php` then adds them: `return [...require __DIR__.'/kit-blocks.php', /* the app's own */];`,
and the page's `Field::blocks('content')->allow([...])` lists the kit blocks it wants. Handles are
prefixed `kit-` so they sit beside the built-ins (`hero` and `kit-hero`) while the redesign
decides which to keep.

### 2. Field mapping

| kit schema type | cg-cms field | Notes |
|---|---|---|
| `text` | `Field::text()->maxLength(max)` | `required` maps across. |
| `textarea` | `Field::textarea()->maxLength(max)` | |
| `rich-text` | `Field::textarea()` with help: "*Stars* for italics, **double stars** for bold." | kit rich text is plain text with marks, never HTML; it must not become TipTap JSON. Later, a small `marks` admin field (`registerField('marks', …)`) with italic and bold buttons. |
| `url` | `Field::url()` | kit's `kit_url()` still decides what's linked. |
| `image` | `Field::media()->requireAlt()` when the schema's `alt` is `required` | Stored as `{id, alt}`; the wrapper resolves it (below). The schema's `sizes` name the preset. |
| `number` | `Field::number()->min(min)->max(max)` | |
| `boolean` | `Field::boolean()` | |
| `enum` | `Field::select()->options([...])->default(default)` | |
| `heading-level` | `Field::select()->options([1, 2, 3, 4])->default(default)` | |
| `icon` | `Field::select()->options(<kit's icon names>)` | Later, an `icon` admin field that shows them. |
| `list` | `Field::repeater()->min(min)->max(max)->rowLabel(<first text field>)->fields([...])` | Pricing has a list in a list (each price's features): see open question 1. |
| `variants` | a `variant` select, first in the form | The section's layouts (hero: split or centred). |

The per-block light/dark `theme` select isn't added to kit blocks: sections carry their own
background choice (plain, tinted, dark) where Design gives them one.

### 3. Rendering

The wrapper view, generated per section:

```blade
@include('kit.sections.hero', [
    'content' => kit_content('hero', $data),
    'variant' => $data['variant'] ?? 'default',
])
```

`kit_content()` does what kit Blocks does in WordPress, and must give the same result:

- **sanitise:** drop fields the schema doesn't have, drop wrong types, cut text to its maximum,
  empty addresses kit won't link to, keep enums to their options;
- **resolve media:** `{id, alt}` to `{src, alt, width, height}` through `MediaResolver`, at the
  preset the schema's `sizes` names (`hero`, `half`, `card`), plus `srcset` when kit's templates
  take it;
- **fill:** every field present, missing ones at their defaults, so templates read fields
  directly.

The sanitise-and-fill code is in kit Blocks today (`wp-base/plugins/kit-blocks/includes/content.php`).
It moves into kit's runtime (`kit.php`) so cg-cms and WordPress share one copy, with the media
step left to each CMS.

**Previews:** `BlockPreviewController` already renders a block's view, so the admin's canvas shows
the real section with no extra work. The preview page needs kit.css and the section's CSS.

**CSS on the page:** each wrapper `@push`es its section's stylesheet once (`@once`), so a page loads
only the sections it uses; kit.css goes in the layout.

**Page cache:** kit's content sections don't read other collections, so no `dependsOn`. The shop
sections will (below).

### 4. The shop sections and commerce

kit's shop sections (announcement bar, product grid, product page, basket drawer) take content in
a product shape: name, price, was-price, images, options, stock state, add-to-basket action. In
cg-cms they're fed by the commerce package (`docs/commerce-plan.md`), not typed in by the owner:

- an adapter per section maps the commerce models to the section's content (a `Product` to the
  product page's fields, a basket to the drawer's), in the place `resolveItems()` fills `$items`
  for other blocks today;
- those blocks `dependsOn(['products'])` so stock and price changes purge the cache;
- the add-to-basket form posts to commerce's routes; kit's markup takes the action URL and field
  names from the content, so it has no commerce knowledge of its own.

### 5. Tests

- **Same HTML:** `kit build-cgcms` writes kit's fixtures (every demo and the HTML kit renders for
  it, as `kit build-wp -fixtures` does). A PHPUnit test runs each through the cg-cms pipeline
  (`$data` as the admin stores it, the wrapper, Blade) and compares byte for byte.
- **Schema drift:** `kit build-cgcms -check` in CI fails if the generated files are out of date.
- **Admin:** a block of each kind saves, validates (required fields, alt text, list limits) and
  previews.

## Phases

| Phase | Scope | Done when |
|---|---|---|
| 1 | Shared `kit_content()` in kit's runtime; `kit build-cgcms` for the content sections; the wrapper views; kit.css and per-block CSS | A cg-cms page built from kit blocks renders the same HTML as kit's demos, and previews in the admin |
| 2 | The redesign's pages on kit blocks; the built-in blocks it replaces retired | The cg-cms marketing site runs on kit sections |
| 3 | `marks` and `icon` admin fields; `srcset` from media presets | Rich text and icons edit as well as in WordPress |
| 4 | Shop sections fed by commerce (after commerce C1/C2) | A test shop's grid, product page and basket are kit sections |

## Open questions

1. **Lists in lists.** Pricing's features are a list inside each price. Does `Field::repeater()`
   nest in the admin? If not: allow nesting there, or flatten the features into one textarea, a
   line each.
2. **Coexist or replace.** Keep the built-in `hero`, `faq`, `cta`… beside `kit-hero` during the
   redesign, then retire them? Or replace them in one go, with a migration of existing content
   into the kit shapes?
3. **Where the integration lives.** In the cg-cms package (it reads a kit export folder the app
   vendors), or generated straight into each app as above. The plan assumes the app, as
   `cms:block` does.
4. **Rich text.** Is kit's marks-only rich text enough for every kit block (headings, ledes,
   quotes), with TipTap kept for the `rich-text` block's long-form content?
