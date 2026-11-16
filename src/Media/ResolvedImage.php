<?php

declare(strict_types=1);

namespace Cg\Cms\Media;

/**
 * Everything a template needs to emit an image, decided before it renders.
 *
 * `sources` maps a MIME type to a URL, best format first, for `<source>` tags.
 * It is empty for a legacy reference, which has one URL and no variants.
 * `srcsets` maps a format (webp, png, jpg) to a srcset of that format's
 * widths, when the preset has smaller ones and the image is wide enough.
 */
final readonly class ResolvedImage
{
    /**
     * @param  array<string, string>  $sources
     * @param  array<string, string>  $srcsets
     */
    public function __construct(
        public string $src,
        public string $alt,
        public ?int $width = null,
        public ?int $height = null,
        public array $sources = [],
        public array $srcsets = [],
    ) {}
}
