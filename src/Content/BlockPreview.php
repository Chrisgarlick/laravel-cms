<?php

declare(strict_types=1);

namespace Cg\Cms\Content;

use Cg\Cms\Schema\BlockRegistry;
use Cg\Cms\Schema\Field;
use Illuminate\Support\Facades\View;

/**
 * Renders unsaved blocks exactly as the site will.
 *
 * Rich text is normally rendered once, on save, by the entry observer. A
 * preview has nothing saved, so this does the same walk over the block's
 * fields (top level, repeater rows, groups) with the same TiptapRenderer,
 * then hands the result to the same BlockRenderer a page uses. Same
 * templates, same HTML, nothing written.
 */
final class BlockPreview
{
    public function __construct(
        private readonly BlockRenderer $renderer,
        private readonly BlockRegistry $blocks,
        private readonly TiptapRenderer $tiptap,
    ) {}

    /**
     * @param  array<int, mixed>  $blocks
     */
    public function body(array $blocks): string
    {
        $rendered = [];

        foreach (array_values($blocks) as $index => $block) {
            $definition = is_array($block) ? $this->blocks->find((string) ($block['type'] ?? '')) : null;

            if ($definition === null) {
                continue;
            }

            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $rendered[$index] = $this->renderFields($definition->allFields(), $data);
        }

        return $this->renderer->render(array_values($blocks), $rendered);
    }

    /**
     * The body inside a document carrying the site's stylesheet.
     *
     * The blocks render first and the document after, but as one render as
     * far as Blade is concerned: what a block pushes (a kit block pushes its
     * section's CSS onto `kit-styles`) is still there when the document's
     * head prints the stack. Rendered separately, Blade would flush the stacks
     * between the two and the preview would lose the section's CSS.
     *
     * $fields are the entry's other simple values (a page's mode, its title),
     * unsaved edits included, given to the view as `$fields`, with the posted
     * blocks as `$blocks`, so a site's preview document can dress itself the
     * way its layout would (a theme's mode, a page class for some blocks).
     *
     * @param  array<int, mixed>  $blocks
     * @param  array<string, string|int|float|bool|null>  $fields
     */
    public function document(array $blocks, array $fields = []): string
    {
        $view = View::exists('cms.block-preview') ? 'cms.block-preview' : 'cgcms::block-preview';
        $factory = View::getFacadeRoot();

        $factory->incrementRender();

        try {
            $body = $this->body($blocks);

            return View::make($view, ['body' => $body, 'fields' => $fields, 'blocks' => array_values($blocks)])->render();
        } finally {
            $factory->decrementRender();
            $factory->flushStateIfDoneRendering();
        }
    }

    /**
     * @param  array<int, Field>  $fields
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function renderFields(array $fields, array $data): array
    {
        $html = [];

        foreach ($fields as $field) {
            $value = $data[$field->name] ?? null;

            if ($field->type === 'richText') {
                $html[$field->name] = $this->tiptap->render(is_array($value) || is_string($value) ? $value : null);
            } elseif ($field->type === 'repeater' && is_array($value)) {
                $html[$field->name] = array_map(
                    fn (mixed $row): array => is_array($row) ? $this->renderFields($field->subFields(), $row) : [],
                    array_values($value),
                );
            } elseif ($field->type === 'group' && is_array($value)) {
                $html[$field->name] = $this->renderFields($field->subFields(), $value);
            }
        }

        return $html;
    }
}
