<?php

declare(strict_types=1);

namespace Cg\Cms\Content;

/**
 * Renders a TipTap document to HTML.
 *
 * PHP port of the `tiptapToHtml` helper that currently lives in the Astro
 * theme's src/lib/cms.ts. Three deliberate differences:
 *
 *  1. It runs on save, not on render. Output is stored in entries.rendered, so
 *     serving a page never parses a document.
 *  2. It is the only renderer. The old stack had one path for top-level rich
 *     text and a hand-written fallback for rich text inside blocks, which is
 *     why block content rendered differently (Kritano issue 3d).
 *  3. Escaping happens exactly once, here. The old renderer double-escaped
 *     ampersands, which is why `&mdash;` appeared literally on the page
 *     (Kritano issue 16). Authors should not be writing entities anyway, and
 *     the lint rules reject them, but the renderer no longer makes it worse.
 */
final class TiptapRenderer
{
    /** Marks are applied innermost-first so nesting order is deterministic. */
    private const MARK_ORDER = ['code', 'italic', 'bold', 'link'];

    /**
     * @param  string|null  $siteDomain  Used only to tell internal links from
     *                                   external ones. Injected rather than
     *                                   read from config() so the renderer is a
     *                                   pure function of its input and can be
     *                                   unit tested without booting Laravel.
     */
    public function __construct(private readonly ?string $siteDomain = null) {}

    /** @param array<string, mixed>|string|null $document */
    public function render(array|string|null $document): string
    {
        if ($document === null || $document === '') {
            return '';
        }

        // Already-rendered HTML passes through untouched.
        if (is_string($document)) {
            return $document;
        }

        if (isset($document['html']) && is_string($document['html'])) {
            return $document['html'];
        }

        return $this->renderNode($document);
    }

    /** @param array<string, mixed> $node */
    private function renderNode(array $node): string
    {
        $type = $node['type'] ?? null;

        if ($type === 'text') {
            return $this->renderText($node);
        }

        $children = $this->renderChildren($node);

        return match ($type) {
            'doc' => $children,
            'paragraph' => "<p>{$children}</p>",
            'heading' => $this->renderHeading($node, $children),
            'bulletList' => "<ul>{$children}</ul>",
            'orderedList' => "<ol>{$children}</ol>",
            'listItem' => "<li>{$children}</li>",
            'blockquote' => "<blockquote>{$children}</blockquote>",
            'codeBlock' => $this->renderCodeBlock($node, $children),
            'horizontalRule' => '<hr>',
            'hardBreak' => '<br>',
            'image' => $this->renderImage($node),
            // Wide tables must scroll inside their own container rather than
            // making the page scroll horizontally. The wrapper class matches
            // the existing theme's prose styles.
            'table' => '<div class="prose-table-wrap"><table>'.$children.'</table></div>',
            'tableRow' => "<tr>{$children}</tr>",
            'tableHeader' => "<th>{$children}</th>",
            'tableCell' => "<td>{$children}</td>",
            default => $children,
        };
    }

    /** @param array<string, mixed> $node */
    private function renderChildren(array $node): string
    {
        $content = $node['content'] ?? [];

        if (! is_array($content)) {
            return '';
        }

        $html = '';

        foreach ($content as $child) {
            if (is_array($child)) {
                $html .= $this->renderNode($child);
            }
        }

        return $html;
    }

    /** @param array<string, mixed> $node */
    private function renderText(array $node): string
    {
        $text = $this->escape((string) ($node['text'] ?? ''));
        $marks = $node['marks'] ?? [];

        if (! is_array($marks) || $marks === []) {
            return $text;
        }

        // Sort so identical mark sets always nest identically. Without this,
        // the same content can produce different HTML depending on the order
        // TipTap happened to store the marks, which breaks snapshot tests.
        usort($marks, function (array $a, array $b): int {
            $ai = array_search($a['type'] ?? '', self::MARK_ORDER, true);
            $bi = array_search($b['type'] ?? '', self::MARK_ORDER, true);

            return ($ai === false ? PHP_INT_MAX : $ai) <=> ($bi === false ? PHP_INT_MAX : $bi);
        });

        foreach ($marks as $mark) {
            $text = $this->applyMark($text, is_array($mark) ? $mark : []);
        }

        return $text;
    }

    /** @param array<string, mixed> $mark */
    private function applyMark(string $text, array $mark): string
    {
        return match ($mark['type'] ?? null) {
            'bold' => "<strong>{$text}</strong>",
            'italic' => "<em>{$text}</em>",
            'code' => "<code>{$text}</code>",
            'strike' => "<s>{$text}</s>",
            'underline' => "<u>{$text}</u>",
            'link' => $this->renderLink($text, $mark),
            default => $text,
        };
    }

    /** @param array<string, mixed> $mark */
    private function renderLink(string $text, array $mark): string
    {
        $href = (string) data_get($mark, 'attrs.href', '');

        if ($href === '') {
            return $text;
        }

        $attrs = 'href="'.$this->escapeAttribute($href).'"';

        // External links get rel="noopener". Internal ones do not need it and
        // the extra attribute is bytes on every page.
        if ($this->isExternal($href)) {
            $attrs .= ' rel="noopener"';
        }

        return "<a {$attrs}>{$text}</a>";
    }

    /** @param array<string, mixed> $node */
    private function renderHeading(array $node, string $children): string
    {
        // Clamp to h2 to h6. An entry never emits its own h1: the page title is
        // the h1, and two of them is an SEO problem the lint rules flag.
        $level = (int) data_get($node, 'attrs.level', 2);
        $level = max(2, min(6, $level));

        return "<h{$level}>{$children}</h{$level}>";
    }

    /** @param array<string, mixed> $node */
    private function renderCodeBlock(array $node, string $children): string
    {
        $language = data_get($node, 'attrs.language');

        $class = is_string($language) && $language !== ''
            ? ' class="language-'.$this->escapeAttribute($language).'"'
            : '';

        return "<pre><code{$class}>{$children}</code></pre>";
    }

    /** @param array<string, mixed> $node */
    private function renderImage(array $node): string
    {
        $src = (string) data_get($node, 'attrs.src', '');

        if ($src === '') {
            return '';
        }

        $html = '<img src="'.$this->escapeAttribute($src).'"';
        $html .= ' alt="'.$this->escapeAttribute((string) data_get($node, 'attrs.alt', '')).'"';

        // Dimensions are emitted whenever known. Missing intrinsic size is the
        // single most common cause of layout shift.
        foreach (['width', 'height'] as $dimension) {
            $value = data_get($node, "attrs.{$dimension}");

            if ($value !== null && $value !== '') {
                $html .= ' '.$dimension.'="'.$this->escapeAttribute((string) $value).'"';
            }
        }

        return $html.' loading="lazy" decoding="async">';
    }

    private function isExternal(string $href): bool
    {
        if (! str_starts_with($href, 'http://') && ! str_starts_with($href, 'https://')) {
            return false;
        }

        $host = parse_url($href, PHP_URL_HOST);

        if (! is_string($host)) {
            return false;
        }

        if ($this->siteDomain === null) {
            return true;
        }

        return $host !== parse_url($this->siteDomain, PHP_URL_HOST);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', double_encode: true);
    }

    private function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', double_encode: true);
    }
}
