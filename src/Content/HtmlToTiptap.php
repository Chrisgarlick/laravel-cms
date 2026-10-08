<?php

declare(strict_types=1);

namespace Cg\Cms\Content;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Turns rendered rich-text HTML back into a TipTap document.
 *
 * Covers the subset the live site's renderer produced: paragraphs, headings,
 * lists, blockquotes, code blocks, rules, tables, images and the inline marks.
 * Output is shaped so Cg\Cms\Content\TiptapRenderer renders it back to the
 * same HTML, which is the property the tests hold it to.
 *
 * Used to build dev content from the built Astro site, where only the HTML
 * survives. The Phase 6 import reads TipTap JSON from the old database and
 * does not need this.
 */
final class HtmlToTiptap
{
    private const BLOCK_TAGS = [
        'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'blockquote',
        'pre', 'hr', 'table', 'div', 'img', 'section', 'article', 'figure',
    ];

    /** @return array{type: string, content: array<int, array<string, mixed>>} */
    public function convert(string $html): array
    {
        $document = new DOMDocument;
        @$document->loadHTML(
            '<?xml encoding="UTF-8"><body>'.$html.'</body>',
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET,
        );

        $body = $document->getElementsByTagName('body')->item(0);

        return [
            'type' => 'doc',
            'content' => $body === null ? [] : $this->blocks($body),
        ];
    }

    /**
     * Block-level children of an element. Stray inline content is gathered
     * into paragraphs, because TipTap does not allow it at block level.
     *
     * @return array<int, array<string, mixed>>
     */
    private function blocks(DOMNode $parent): array
    {
        $blocks = [];
        $pending = [];

        $flush = function () use (&$pending, &$blocks): void {
            $inline = $this->trimInline($pending);
            $pending = [];

            if ($inline !== []) {
                $blocks[] = ['type' => 'paragraph', 'content' => $inline];
            }
        };

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->nodeName), self::BLOCK_TAGS, true)) {
                $flush();
                $blocks = [...$blocks, ...$this->block($child)];

                continue;
            }

            $pending = [...$pending, ...$this->inline($child, [])];
        }

        $flush();

        return $blocks;
    }

    /** @return array<int, array<string, mixed>> */
    private function block(DOMElement $element): array
    {
        $tag = strtolower($element->nodeName);

        return match ($tag) {
            'p' => $this->paragraph($element),
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => [[
                'type' => 'heading',
                'attrs' => ['level' => (int) substr($tag, 1)],
                'content' => $this->inlineOf($element),
            ]],
            'ul', 'ol' => [$this->listNode($element, $tag === 'ul' ? 'bulletList' : 'orderedList')],
            'blockquote' => [['type' => 'blockquote', 'content' => $this->blocks($element)]],
            'pre' => [$this->codeBlock($element)],
            'hr' => [['type' => 'horizontalRule']],
            'img' => [$this->image($element)],
            'table' => [$this->table($element)],
            // Wrappers, the table scroller included: their content is what counts.
            default => $this->blocks($element),
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function paragraph(DOMElement $element): array
    {
        $content = $this->inlineOf($element);

        return [$content === [] ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => $content]];
    }

    /** @return array<string, mixed> */
    private function listNode(DOMElement $element, string $type): array
    {
        $items = [];

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'li') {
                $items[] = ['type' => 'listItem', 'content' => $this->blocks($child)];
            }
        }

        $node = ['type' => $type, 'content' => $items];

        if ($type === 'orderedList' && $element->hasAttribute('start')) {
            $node['attrs'] = ['start' => (int) $element->getAttribute('start')];
        }

        return $node;
    }

    /** @return array<string, mixed> */
    private function codeBlock(DOMElement $pre): array
    {
        $code = null;

        foreach ($pre->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'code') {
                $code = $child;
            }
        }

        $text = ($code ?? $pre)->textContent;
        $node = ['type' => 'codeBlock'];

        if ($code !== null && preg_match('/language-([\w-]+)/', $code->getAttribute('class'), $m) === 1) {
            $node['attrs'] = ['language' => $m[1]];
        }

        if ($text !== '') {
            $node['content'] = [['type' => 'text', 'text' => $text]];
        }

        return $node;
    }

    /** @return array<string, mixed> */
    private function image(DOMElement $img): array
    {
        $attrs = ['src' => $img->getAttribute('src'), 'alt' => $img->getAttribute('alt')];

        foreach (['width', 'height'] as $dimension) {
            if ($img->hasAttribute($dimension)) {
                $attrs[$dimension] = $img->getAttribute($dimension);
            }
        }

        return ['type' => 'image', 'attrs' => $attrs];
    }

    /** @return array<string, mixed> */
    private function table(DOMElement $table): array
    {
        $rows = [];

        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];

            foreach ($tr->childNodes as $cell) {
                if (! $cell instanceof DOMElement) {
                    continue;
                }

                $tag = strtolower($cell->nodeName);

                if ($tag !== 'th' && $tag !== 'td') {
                    continue;
                }

                $cells[] = [
                    'type' => $tag === 'th' ? 'tableHeader' : 'tableCell',
                    'content' => $this->blocks($cell),
                ];
            }

            $rows[] = ['type' => 'tableRow', 'content' => $cells];
        }

        return ['type' => 'table', 'content' => $rows];
    }

    /** @return array<int, array<string, mixed>> */
    private function inlineOf(DOMElement $element): array
    {
        $content = [];

        foreach ($element->childNodes as $child) {
            $content = [...$content, ...$this->inline($child, [])];
        }

        return $this->trimInline($content);
    }

    /**
     * @param  array<int, array<string, mixed>>  $marks
     * @return array<int, array<string, mixed>>
     */
    private function inline(DOMNode $node, array $marks): array
    {
        if ($node instanceof DOMText) {
            $text = (string) preg_replace('/\s+/u', ' ', $node->textContent);

            if ($text === '') {
                return [];
            }

            $out = ['type' => 'text', 'text' => $text];

            if ($marks !== []) {
                $out['marks'] = $marks;
            }

            return [$out];
        }

        if (! $node instanceof DOMElement) {
            return [];
        }

        $tag = strtolower($node->nodeName);

        if ($tag === 'br') {
            return [['type' => 'hardBreak']];
        }

        if ($tag === 'img') {
            return [$this->image($node)];
        }

        $mark = match ($tag) {
            'strong', 'b' => ['type' => 'bold'],
            'em', 'i' => ['type' => 'italic'],
            'code' => ['type' => 'code'],
            's', 'del' => ['type' => 'strike'],
            'u' => ['type' => 'underline'],
            'a' => $node->getAttribute('href') !== ''
                ? ['type' => 'link', 'attrs' => ['href' => $node->getAttribute('href')]]
                : null,
            default => null,
        };

        $childMarks = $mark === null ? $marks : [...$marks, $mark];
        $out = [];

        foreach ($node->childNodes as $child) {
            $out = [...$out, ...$this->inline($child, $childMarks)];
        }

        return $out;
    }

    /**
     * Drop whitespace at the edges of a block and merge adjacent text with
     * identical marks, so formatting whitespace in the HTML leaves no trace.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, array<string, mixed>>
     */
    private function trimInline(array $nodes): array
    {
        $merged = [];

        foreach ($nodes as $node) {
            $last = array_key_last($merged);

            if (
                $last !== null
                && ($node['type'] ?? null) === 'text'
                && ($merged[$last]['type'] ?? null) === 'text'
                && ($merged[$last]['marks'] ?? []) === ($node['marks'] ?? [])
            ) {
                $merged[$last]['text'] .= $node['text'];

                continue;
            }

            $merged[] = $node;
        }

        $first = array_key_first($merged);

        if ($first !== null && ($merged[$first]['type'] ?? null) === 'text') {
            $merged[$first]['text'] = ltrim($merged[$first]['text']);
        }

        $last = array_key_last($merged);

        if ($last !== null && ($merged[$last]['type'] ?? null) === 'text') {
            $merged[$last]['text'] = rtrim($merged[$last]['text']);
        }

        return array_values(array_filter(
            $merged,
            fn (array $node): bool => ($node['type'] ?? null) !== 'text' || $node['text'] !== '',
        ));
    }
}
