<?php

declare(strict_types=1);

namespace Cg\Cms\Content;

use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Markdown to a TipTap document, so content written outside the admin (by
 * hand, or by Claude Code through the CLI) lands as ordinary editable rich
 * text rather than a blob of markup.
 *
 * GitHub-flavoured CommonMark (league/commonmark ships with the framework),
 * then the same HTML converter the importers use. Raw HTML is stripped and
 * unsafe links refused. A level-one heading is dropped, because the entry's
 * title is the page's h1, and h5/h6 are raised to h4 to match the editor.
 */
final class MarkdownToTiptap
{
    public function __construct(private readonly HtmlToTiptap $html) {}

    /** @return array<string, mixed> */
    public function convert(string $markdown): array
    {
        $markdown = (string) preg_replace('/<!--.*?-->/s', '', $markdown);

        $html = (string) (new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]))->convert($markdown);

        $html = (string) preg_replace('#<h1[^>]*>.*?</h1>#s', '', $html);
        $html = (string) preg_replace('#<(/?)h[56]([^>]*)>#', '<$1h4$2>', $html);

        return $this->html->convert($html);
    }
}
