<?php

declare(strict_types=1);

namespace Cg\Cms\Media;

use GdImage;
use RuntimeException;

/**
 * The image driver that is always there.
 *
 * GD decodes the whole image into memory at four bytes a pixel, which is why
 * originals are capped at 4000px and processed on the queue: a capped original
 * costs at most 64MB to open, and one queue worker doing that is affordable on
 * a 1GB box where four PHP-FPM workers doing it at once would not be.
 */
final class GdProcessor implements ImageProcessor
{
    public function supports(string $format): bool
    {
        return match ($format) {
            'jpg', 'jpeg' => function_exists('imagejpeg'),
            'png' => function_exists('imagepng'),
            'webp' => function_exists('imagewebp') && (bool) (gd_info()['WebP Support'] ?? false),
            'avif' => function_exists('imageavif') && (bool) (gd_info()['AVIF Support'] ?? false),
            default => false,
        };
    }

    public function dimensions(string $path): array
    {
        $size = @getimagesize($path);

        if ($size === false) {
            throw new RuntimeException("Not a readable image: {$path}");
        }

        [$width, $height] = $size;

        return $this->isQuarterTurned($this->orientation($path))
            ? ['width' => $height, 'height' => $width]
            : ['width' => $width, 'height' => $height];
    }

    public function resize(
        string $source,
        string $destination,
        string $format,
        int $width,
        ?int $height,
        float $focalX,
        float $focalY,
        int $quality,
    ): array {
        $image = $this->load($source);

        $sourceWidth = imagesx($image);
        $sourceHeight = imagesy($image);

        [$cropX, $cropY, $cropWidth, $cropHeight] = CropGeometry::box(
            $sourceWidth, $sourceHeight, $width, $height, $focalX, $focalY,
        );

        ['width' => $outputWidth, 'height' => $outputHeight] = CropGeometry::output(
            $sourceWidth, $sourceHeight, $width, $height,
        );

        $output = imagecreatetruecolor($outputWidth, $outputHeight);

        if ($output === false) {
            throw new RuntimeException('Could not allocate the output image.');
        }

        $this->prepareCanvas($output, $format);

        imagecopyresampled(
            $output, $image,
            0, 0, $cropX, $cropY,
            $outputWidth, $outputHeight, $cropWidth, $cropHeight,
        );

        imagedestroy($image);

        $this->write($output, $destination, $format, $quality);

        imagedestroy($output);

        return ['width' => $outputWidth, 'height' => $outputHeight];
    }

    public function constrain(string $path, int $maxDimension): ?array
    {
        $orientation = $this->orientation($path);
        $size = @getimagesize($path);

        if ($size === false) {
            throw new RuntimeException("Not a readable image: {$path}");
        }

        if (max($size[0], $size[1]) <= $maxDimension && $orientation <= 1) {
            return null;
        }

        $image = $this->load($path);
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $maxDimension / max($width, $height));

        $format = match ($size[2]) {
            IMAGETYPE_PNG, IMAGETYPE_GIF => 'png',
            IMAGETYPE_WEBP => 'webp',
            default => 'jpg',
        };

        $output = imagecreatetruecolor(
            (int) max(1, round($width * $scale)),
            (int) max(1, round($height * $scale)),
        );

        if ($output === false) {
            throw new RuntimeException('Could not allocate the output image.');
        }

        $this->prepareCanvas($output, $format);
        imagecopyresampled($output, $image, 0, 0, 0, 0, imagesx($output), imagesy($output), $width, $height);
        imagedestroy($image);

        // High quality, because this replaces the original and every variant
        // is encoded from it. Compressing here would compress twice.
        $this->write($output, $path, $format, 92);

        $dimensions = ['width' => imagesx($output), 'height' => imagesy($output)];
        imagedestroy($output);

        return $dimensions;
    }

    private function load(string $path): GdImage
    {
        $contents = @file_get_contents($path);
        $image = $contents === false ? false : @imagecreatefromstring($contents);

        if ($image === false) {
            throw new RuntimeException("Could not decode image: {$path}");
        }

        // Palette images (GIF, 8-bit PNG) resample badly and cannot hold the
        // alpha channel the canvas is about to be given.
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return $this->applyOrientation($image, $this->orientation($path));
    }

    /**
     * Transparent for formats that can hold it, white for JPEG.
     *
     * Without this a transparent PNG resampled onto GD's default canvas comes
     * out with a black background, which is how a logo on a card turns into
     * a black rectangle.
     */
    private function prepareCanvas(GdImage $image, string $format): void
    {
        if ($format === 'jpg' || $format === 'jpeg') {
            imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));

            return;
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
    }

    /**
     * Encode to a temporary file and rename into place.
     *
     * nginx may be serving the destination path the moment it exists, so a
     * half-written file must never be visible there. rename() within one
     * filesystem is atomic.
     */
    private function write(GdImage $image, string $destination, string $format, int $quality): void
    {
        $directory = dirname($destination);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create {$directory}");
        }

        $temporary = $destination.'.'.bin2hex(random_bytes(4)).'.tmp';

        $written = match ($format) {
            'jpg', 'jpeg' => (function () use ($image, $temporary, $quality): bool {
                // Progressive JPEGs render a whole low-quality image first
                // rather than a sharp strip from the top down.
                imageinterlace($image, true);

                return imagejpeg($image, $temporary, $quality);
            })(),
            'png' => imagepng($image, $temporary, 6),
            'webp' => imagewebp($image, $temporary, $quality),
            'avif' => imageavif($image, $temporary, $quality),
            default => throw new RuntimeException("Unsupported format [{$format}]."),
        };

        if (! $written || ! @rename($temporary, $destination)) {
            @unlink($temporary);

            throw new RuntimeException("Could not write {$destination}");
        }

        @chmod($destination, 0644);
    }

    private function orientation(string $path): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $type = @exif_imagetype($path);

        if ($type !== IMAGETYPE_JPEG) {
            return 1;
        }

        $exif = @exif_read_data($path);

        return is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
    }

    private function isQuarterTurned(int $orientation): bool
    {
        return in_array($orientation, [5, 6, 7, 8], true);
    }

    private function applyOrientation(GdImage $image, int $orientation): GdImage
    {
        if ($orientation <= 1 || $orientation > 8) {
            return $image;
        }

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        // imagerotate() turns anticlockwise for a positive angle. After the
        // mirror, 5 is a quarter turn anticlockwise and 7 a quarter turn
        // clockwise, which is the pair most implementations get backwards.
        $angle = match ($orientation) {
            3, 4 => 180,
            6, 7 => -90,
            5, 8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }
}
