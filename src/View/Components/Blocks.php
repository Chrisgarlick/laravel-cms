<?php

declare(strict_types=1);

namespace Cg\Cms\View\Components;

use Cg\Cms\Content\BlockRenderer;
use Cg\Cms\Models\Entry;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;

/**
 * Renders an entry's blocks.
 *
 *     <x-cms-blocks :entry="$page" />
 *
 * All the work (dependency registration, cross-collection queries, nested
 * rich text) happens in BlockRenderer. This component only hands it the
 * authored list and the pre-rendered HTML.
 */
final class Blocks extends Component
{
    public function __construct(
        public Entry $entry,
        public string $field = 'content',
    ) {}

    /**
     * Returns Htmlable, never a plain string. This is a security requirement,
     * not a style choice.
     *
     * Illuminate\View\Component::resolveView() returns Htmlable and View
     * as-is, but passes anything else to extractBladeViewFromString(). So
     * returning a string here would compile our already-rendered block HTML
     * as a Blade template, meaning author content containing {{ ... }},
     * {!! ... !!} or @php would be executed. That is template injection via
     * the CMS editor.
     *
     * It is also wasteful: every distinct block payload would be compiled and
     * cached as its own view file.
     */
    public function render(): Htmlable
    {
        $blocks = (array) $this->entry->value($this->field, []);

        if ($blocks === []) {
            return new HtmlString('');
        }

        $rendered = (array) data_get($this->entry->rendered, $this->field, []);

        return new HtmlString(
            app(BlockRenderer::class)->render($blocks, $rendered),
        );
    }
}
