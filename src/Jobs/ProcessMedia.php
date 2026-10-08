<?php

declare(strict_types=1);

namespace Cg\Cms\Jobs;

use Cg\Cms\Media\MediaLibrary;
use Cg\Cms\Models\Media;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Cap an original and write all of its variants.
 *
 * On the queue because section 5.6 says so and the box agrees: encoding five
 * presets in two formats from a 4000px original takes seconds and tens of
 * megabytes, and doing that inside an upload request would hold a PHP-FPM
 * worker that a visitor is waiting for.
 *
 * Takes an id rather than the model, so a job that outlives its image (deleted
 * a second after upload) ends quietly rather than failing to unserialise.
 */
final class ProcessMedia implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public readonly int $mediaId) {}

    public function handle(MediaLibrary $library): void
    {
        $media = Media::query()->find($this->mediaId);

        if ($media === null) {
            return;
        }

        $library->process($media);
    }
}
