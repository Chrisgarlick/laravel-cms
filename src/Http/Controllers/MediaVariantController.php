<?php

declare(strict_types=1);

namespace Cg\Cms\Http\Controllers;

use Cg\Cms\Media\MediaLibrary;
use Cg\Cms\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A variant nginx did not find on disk.
 *
 * nginx tries the file first and only falls through to PHP when it is missing,
 * so this runs once per variant, ever: it writes the file, and the next request
 * for the same URL never reaches PHP. Normally the queue has already written
 * every variant before anyone asks, and this is the path for a preset added to
 * config after the image was uploaded.
 *
 * No session, no cookies, no page cache. An image response with Set-Cookie
 * would be uncacheable at the edge for no reason at all.
 */
final class MediaVariantController
{
    public function __invoke(MediaLibrary $library, string $uuid, string $preset, string $format): BinaryFileResponse
    {
        $media = Media::query()->where('uuid', $uuid)->first();

        // Only presets in config and formats this image is offered in. Without
        // the check, any string in the URL would be a request to encode a new
        // file, and a crawler walking made-up URLs would fill the disk.
        if (
            $media === null
            || $library->preset($preset) === null
            || ! in_array($format, $library->formatsFor($media), true)
        ) {
            throw new NotFoundHttpException;
        }

        $path = $library->variant($media, $preset, $format);

        return response()->file($path, [
            'Content-Type' => match ($format) {
                'jpg' => 'image/jpeg',
                default => 'image/'.$format,
            },

            // Not immutable. Moving a focal point changes the pixels behind an
            // unchanged URL; the CDN is purged when that happens, and a week
            // is the longest a browser keeps the old crop.
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }
}
