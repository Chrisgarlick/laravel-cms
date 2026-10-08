<?php

declare(strict_types=1);

namespace Cg\Cms\View\Components;

use Cg\Cms\Media\MediaResolver;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;

/**
 * An image from a media field, as `<picture>` with width and height.
 *
 *     <x-cms-image :value="$entry->value('cover_image')" preset="card" />
 *
 * Width and height are always emitted when they are known, which for a library
 * image is always. That is the CLS half of section 5.6; the accessibility half
 * is that alt is emitted even when empty, because a missing alt attribute makes
 * a screen reader read out the filename, and an empty one tells it the image
 * is decorative.
 *
 * Returns Htmlable for the same template-injection reason as Blocks.
 */
final class Image extends Component
{
    public function __construct(
        public mixed $value,
        public string $preset = 'full',
        public ?string $class = null,

        // Lazy by default. The one image above the fold should say eager,
        // with fetchpriority high, and every other image should not compete
        // with it for bandwidth.
        public string $loading = 'lazy',
        public ?string $fetchpriority = null,
    ) {}

    public function render(): Htmlable
    {
        $image = app(MediaResolver::class)->resolve($this->value, $this->preset);

        if ($image === null) {
            return new HtmlString('');
        }

        $attributes = array_filter([
            'src' => $image->src,
            'alt' => $image->alt,
            'width' => $image->width,
            'height' => $image->height,
            'class' => $this->class,
            'loading' => $this->loading,
            'decoding' => 'async',
            'fetchpriority' => $this->fetchpriority,
        ], fn ($value): bool => $value !== null);

        // alt survives the filter above as an empty string, deliberately.
        $img = '<img';

        foreach ($attributes as $name => $value) {
            $img .= ' '.$name.'="'.e((string) $value).'"';
        }

        $img .= '>';

        if ($image->sources === []) {
            return new HtmlString($img);
        }

        $sources = '';

        foreach ($image->sources as $type => $url) {
            $sources .= '<source type="'.e($type).'" srcset="'.e($url).'">';
        }

        return new HtmlString('<picture>'.$sources.$img.'</picture>');
    }
}
