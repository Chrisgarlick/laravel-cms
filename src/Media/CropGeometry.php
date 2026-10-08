<?php

declare(strict_types=1);

namespace Cg\Cms\Media;

/**
 * The arithmetic of a variant, shared by the encoder and the markup.
 *
 * The `<img>` tag needs a variant's width and height before the variant
 * exists, because the page is rendered before the first visitor's browser asks
 * for the file. Two copies of this logic, one in the encoder and one in the
 * component, would agree until somebody changed one of them, and the symptom
 * would be layout shift on every image: exactly what width and height are
 * there to prevent.
 */
final class CropGeometry
{
    /**
     * The largest box of the target shape that fits, centred on the focal point.
     *
     * Clamped to the edges, so a focal point in a corner moves the crop as far
     * towards it as the image allows rather than running off the side.
     *
     * @return array{0: int, 1: int, 2: int, 3: int} x, y, width, height
     */
    public static function box(int $width, int $height, int $targetWidth, ?int $targetHeight, float $focalX, float $focalY): array
    {
        if ($targetHeight === null) {
            return [0, 0, $width, $height];
        }

        $ratio = $targetWidth / $targetHeight;

        $cropWidth = $width;
        $cropHeight = (int) round($width / $ratio);

        if ($cropHeight > $height) {
            $cropHeight = $height;
            $cropWidth = (int) round($height * $ratio);
        }

        $cropWidth = max(1, $cropWidth);
        $cropHeight = max(1, $cropHeight);

        $x = (int) round($focalX * $width - $cropWidth / 2);
        $y = (int) round($focalY * $height - $cropHeight / 2);

        return [
            max(0, min($x, $width - $cropWidth)),
            max(0, min($y, $height - $cropHeight)),
            $cropWidth,
            $cropHeight,
        ];
    }

    /**
     * What a variant's dimensions will be. Never larger than the source.
     *
     * @return array{width: int, height: int}
     */
    public static function output(int $width, int $height, int $targetWidth, ?int $targetHeight): array
    {
        [, , $cropWidth, $cropHeight] = self::box($width, $height, $targetWidth, $targetHeight, 0.5, 0.5);

        $outputWidth = min($targetWidth, $cropWidth);

        return [
            'width' => $outputWidth,
            'height' => (int) max(1, round($cropHeight * $outputWidth / $cropWidth)),
        ];
    }
}
