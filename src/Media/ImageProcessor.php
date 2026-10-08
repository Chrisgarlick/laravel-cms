<?php

declare(strict_types=1);

namespace Cg\Cms\Media;

/**
 * Turns an original into a variant.
 *
 * An interface because section 5.6 wants libvips where it is installed and GD
 * where it is not, and because which formats can be encoded is a fact about
 * the server rather than the code: GD on one box has AVIF, the same GD on a
 * laptop does not. Everything that emits a URL asks supports() first.
 */
interface ImageProcessor
{
    /** Whether this driver can encode the format on this server. */
    public function supports(string $format): bool;

    /**
     * Width and height as the image will display, EXIF orientation applied.
     *
     * A portrait phone photo is stored landscape with a flag saying "rotate
     * me". Reporting the stored dimensions would emit a width and height
     * that are the wrong way round, which is the CLS problem width and
     * height exist to solve, reintroduced.
     *
     * @return array{width: int, height: int}
     */
    public function dimensions(string $path): array;

    /**
     * Write a variant, cropped to fill around the focal point.
     *
     * Never upscales. A 400px original asked for a 1200px hero becomes the
     * largest crop of the right shape it can provide, at its own resolution,
     * and the dimensions returned say so. A blurred upscale is worse than a
     * smaller image the browser stretches, because it is also a bigger file.
     *
     * @param  int|null  $height  Null keeps the aspect ratio and bounds width only.
     * @return array{width: int, height: int}
     */
    public function resize(
        string $source,
        string $destination,
        string $format,
        int $width,
        ?int $height,
        float $focalX,
        float $focalY,
        int $quality,
    ): array;

    /**
     * Scale an original down in place if its longest edge is over the limit.
     *
     * Also bakes in the EXIF orientation, which as a side effect drops the
     * rest of the EXIF block, GPS included.
     *
     * @return array{width: int, height: int}|null Null when nothing changed.
     */
    public function constrain(string $path, int $maxDimension): ?array;
}
