<?php

declare(strict_types=1);

namespace Cg\Cms\Schema;

/**
 * A block type: an ordered, typed section of a page.
 *
 * Ported from the block definitions in cms.config.ts and the Astro components
 * in src/components/blocks/. Each block declares its fields, its view, and
 * which collections it queries.
 *
 * That last part is what makes cache invalidation correct. A `blog-preview`
 * block on the homepage means the homepage depends on `collection:article`, so
 * publishing an article purges the homepage. Without the declaration the
 * homepage would go stale silently, which is the failure mode every "clear the
 * whole cache" CMS is working around.
 */
final class Block
{
    /** @var array<int, Field> */
    private array $fields = [];

    /** @var array<int, string> */
    private array $dependencies = [];

    private ?string $view = null;

    private function __construct(
        public readonly string $handle,
        public readonly string $label,
    ) {}

    public static function make(string $handle, string $label): self
    {
        return new self($handle, $label);
    }

    /** @param array<int, Field> $fields */
    public function fields(array $fields): self
    {
        $this->fields = $fields;

        return $this;
    }

    /**
     * Collections this block queries.
     *
     * @param  array<int, string>  $collections
     */
    public function dependsOn(array $collections): self
    {
        $this->dependencies = array_map(
            fn (string $c): string => "collection:{$c}",
            $collections,
        );

        return $this;
    }

    public function view(string $view): self
    {
        $this->view = $view;

        return $this;
    }

    /** @return array<int, Field> */
    public function allFields(): array
    {
        return $this->fields;
    }

    public function field(string $name): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    /** @return array<int, Field> */
    public function richTextFields(): array
    {
        return array_values(array_filter(
            $this->fields,
            fn (Field $f): bool => $f->isRichText(),
        ));
    }

    /** @return array<int, string> */
    public function cacheDependencies(): array
    {
        return $this->dependencies;
    }

    public function viewName(): string
    {
        return $this->view ?? "cgcms::blocks.{$this->handle}";
    }

    /**
     * The application's own blocks, from the file named by `blocks_path`.
     *
     * `php artisan cms:block` adds to that file. A blocks field allows them
     * with `->allow([...Block::builtIns(), ...Block::app()])`. A fresh array
     * of new objects each call, like builtIns(), so nothing is shared.
     *
     * @return array<int, Block>
     */
    public static function app(): array
    {
        $path = config('cg-cms.blocks_path');

        if (! is_string($path) || ! is_file($path)) {
            return [];
        }

        return array_values(array_filter((array) require $path, fn (mixed $block): bool => $block instanceof self));
    }

    /* -----------------------------------------------------------------------
     | Built-in blocks, ported one to one from cms.config.ts
     |----------------------------------------------------------------------- */

    /**
     * The eleven blocks ported from cms.config.ts, plus `faq`.
     *
     * A pure factory: it returns fresh definitions and holds no state, so the
     * registry that owns them can be a container singleton instead.
     *
     * @return array<int, Block>
     */
    public static function builtIns(): array
    {
        $theme = fn (): Field => Field::select('theme')->options(['light', 'dark'])->default('light');
        // The colours a column may wear. A site sets its own palette in
        // cg-cms.block_tints; the default is the original site's services.
        $tint = fn (string $n): Field => Field::select($n)
            ->options(['', ...(array) config('cg-cms.block_tints', ['workflow', 'agents', 'data', 'engineering'])]);

        return [
            self::make('hero', 'Hero')->fields([
                Field::text('label'),
                Field::text('heading')->required(),
                Field::textarea('subtext'),
                Field::text('cta_label'),
                Field::url('cta_url'),
                Field::text('cta_secondary_label'),
                Field::url('cta_secondary_url'),
                $theme(),
            ]),

            self::make('text-section', 'Text section')->fields([
                Field::text('label'),
                Field::text('heading'),
                Field::richText('body')->lint(['no-em-dash', 'no-html-entities']),
                Field::text('cta_label'),
                Field::url('cta_url'),
                $theme(),
            ]),

            // Four fixed columns, matching the existing block. Flattened rather
            // than repeatable because that is how the live content is stored,
            // and reshaping it would complicate the Phase 6 import for no gain.
            self::make('columns', 'Columns')->fields([
                Field::text('label'),
                Field::text('heading'),
                ...self::columnFields(1, $tint),
                ...self::columnFields(2, $tint),
                ...self::columnFields(3, $tint),
                ...self::columnFields(4, $tint),
                $theme(),
            ]),

            self::make('offer-cards', 'Offer cards')->fields([
                ...self::offerCardFields(1),
                ...self::offerCardFields(2),
                $theme(),
            ]),

            self::make('proof-strip', 'Proof strip')
                ->fields([
                    Field::textarea('metrics'),
                    $theme(),
                ])
                ->dependsOn(['proof_metric']),

            self::make('case-study-grid', 'Case study grid')
                ->fields([
                    Field::text('heading'),
                    Field::text('subtext'),
                    Field::number('limit'),
                    $theme(),
                ])
                ->dependsOn(['case_study']),

            self::make('blog-preview', 'Blog preview')
                ->fields([
                    Field::text('heading'),
                    Field::number('limit'),
                    $theme(),
                ])
                ->dependsOn(['article']),

            self::make('cta', 'Call to action')->fields([
                Field::text('heading')->required(),
                Field::textarea('body'),
                Field::text('cta_label'),
                Field::url('cta_url'),
                $theme(),
            ]),

            self::make('tools-teaser', 'Tools teaser')
                ->fields([
                    Field::text('label'),
                    Field::text('tool1_name'),
                    Field::textarea('tool1_body'),
                    Field::text('tool2_name'),
                    Field::textarea('tool2_body'),
                    $theme(),
                ])
                ->dependsOn(['tool']),

            self::make('rich-text', 'Rich text')->fields([
                Field::richText('body')->lint(['no-em-dash', 'no-html-entities']),
                $theme(),
            ]),

            self::make('contact-form', 'Contact form')->fields([
                Field::text('form_slug')->default('contact'),
                $theme(),
            ]),

            /*
             * The first block built on a repeater rather than on flattened
             * fields.
             *
             * An FAQ is the case that made the repeater necessary: the number
             * of questions is not knowable when the block is declared, so the
             * `column1_*` to `column4_*` pattern the older blocks use has no
             * sensible maximum here. It is also the block that makes the
             * FAQPage structured data in section 5.7 emit anything, which it
             * could not before, because there was nothing on a page for it to
             * read.
             */
            self::make('faq', 'FAQ')->fields([
                Field::text('heading'),
                Field::repeater('items')
                    ->label('Questions')
                    ->rowLabel('question')
                    ->min(1)
                    ->fields([
                        Field::text('question')->required()->maxLength(200),
                        Field::richText('answer')->lint(['no-em-dash', 'no-html-entities']),
                    ]),
                $theme(),
            ]),
        ];
    }

    /** @return array<int, Field> */
    private static function columnFields(int $n, callable $tint): array
    {
        return [
            Field::text("column{$n}_heading"),
            Field::textarea("column{$n}_body"),
            Field::url("column{$n}_url"),
            Field::text("column{$n}_cta_label"),
            $tint("column{$n}_tint"),
        ];
    }

    /** @return array<int, Field> */
    private static function offerCardFields(int $n): array
    {
        return [
            Field::text("card{$n}_label"),
            Field::text("card{$n}_name"),
            Field::text("card{$n}_price"),
            Field::text("card{$n}_duration"),
            Field::textarea("card{$n}_features"),
        ];
    }
}
