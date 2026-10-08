<?php

declare(strict_types=1);

namespace Cg\Cms\Seo\Checks;

/**
 * What the SEO checks look at, however it was obtained.
 *
 * The editor builds this from an unsaved entry; the audit builds it from a
 * rendered page. One shape means one set of rules, so the panel beside the
 * form and the nightly report cannot disagree about what a heading problem is.
 */
final readonly class PageFacts
{
    /**
     * @param  array<int, array{level: int, text: string}>  $headings
     * @param  array<int, array{src: string, alt: string|null}>  $images
     * @param  array<int, string>  $internalLinks  Paths, content only.
     * @param  int  $impliedH1  Headings the template adds that the content cannot see: 1
     *                          when the entry's title is rendered as the page h1.
     * @param  string  $contentField  Where content findings are reported in the editor.
     */
    public function __construct(
        public string $title,
        public string $description,
        public array $headings,
        public array $images,
        public array $internalLinks,
        public string $text,
        public ?string $keyword = null,
        public int $impliedH1 = 0,
        public string $contentField = 'content',
    ) {}
}
