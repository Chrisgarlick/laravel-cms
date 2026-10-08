<?php

declare(strict_types=1);

namespace Cg\Cms\Media;

use Cg\Cms\Jobs\ProcessMedia;
use Cg\Cms\Jobs\PurgeCdn;
use Cg\Cms\Models\Entry;
use Cg\Cms\Models\Media;
use Cg\Cms\Models\MediaVariant;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Uploads, variants and usage.
 *
 * The one place that knows where files live. Controllers, the queue job and the
 * public variant route all come through here, so the path of an original and
 * the path of a variant are each decided exactly once.
 */
final class MediaLibrary
{
    /**
     * Decoded size, in pixels, past which an upload is refused outright.
     *
     * A 50 megapixel image costs 200MB to decode before anything can scale it
     * down, and a small, highly compressed PNG can declare dimensions far
     * larger than its file size suggests. Checked from the header, before
     * decoding, which is the only point at which refusing is still cheap.
     */
    private const MAX_PIXELS = 50_000_000;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly ImageProcessor $processor,
        private readonly array $config,
    ) {}

    /**
     * Presets by name, as configured.
     *
     * @return array<string, array{width: int, height: int|null}>
     */
    public function presets(): array
    {
        $presets = [];

        foreach ((array) ($this->config['presets'] ?? []) as $name => $preset) {
            $presets[(string) $name] = [
                'width' => (int) ($preset['width'] ?? 0),
                'height' => isset($preset['height']) ? (int) $preset['height'] : null,
            ];
        }

        return $presets;
    }

    /** @return array{width: int, height: int|null}|null */
    public function preset(string $name): ?array
    {
        return $this->presets()[$name] ?? null;
    }

    /**
     * Modern formats this server can actually encode, best first.
     *
     * Intersected with the processor rather than taken from config, because a
     * `<source type="image/avif">` pointing at a URL that 404s does not fall
     * back to the next source. The browser picks it, fails, and shows a broken
     * image, so listing a format the box cannot produce breaks every image on
     * the site for every visitor whose browser supports it.
     *
     * @return array<int, string>
     */
    public function modernFormats(): array
    {
        return array_values(array_filter(
            (array) ($this->config['formats'] ?? []),
            fn (string $format): bool => $this->processor->supports($format),
        ));
    }

    /** @return array<int, string> Every format a variant of this image may be requested in. */
    public function formatsFor(Media $media): array
    {
        return [...$this->modernFormats(), $media->fallbackFormat()];
    }

    /**
     * Store an upload and queue its variants.
     *
     * The type is read from the file's own header, not from the browser's
     * claim or the extension, both of which are whatever the uploader says
     * they are.
     *
     * An identical file already in the library is returned as is rather than
     * stored again, so the caller can treat "uploaded" and "already had it"
     * the same way.
     *
     * @throws ValidationException
     */
    public function store(UploadedFile $file, ?int $userId = null): Media
    {
        $path = $file->getRealPath();
        $info = $path === false ? false : @getimagesize($path);

        if ($info === false || ! in_array($info['mime'], (array) $this->config['mimes'], true)) {
            throw ValidationException::withMessages([
                'file' => 'Not an image this library accepts. Upload a JPEG, PNG, WebP or GIF.',
            ]);
        }

        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw ValidationException::withMessages([
                'file' => sprintf(
                    'That image is %dx%d pixels, which is more than this server can safely open. Resize it below 50 megapixels first.',
                    $info[0],
                    $info[1],
                ),
            ]);
        }

        $checksum = (string) hash_file('sha256', (string) $path);

        $existing = Media::query()->where('checksum', $checksum)->first();

        if ($existing !== null) {
            return $existing;
        }

        $uuid = (string) Str::uuid();
        $extension = match ($info['mime']) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $disk = (string) $this->config['disk'];
        $stored = Storage::disk($disk)->putFileAs(
            trim((string) ($this->config['directory'] ?? 'cms-media'), '/'),
            $file,
            $uuid.'.'.$extension,
        );

        if ($stored === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be stored.']);
        }

        $dimensions = $this->processor->dimensions((string) $path);

        $media = Media::query()->create([
            'uuid' => $uuid,
            'disk' => $disk,
            'path' => $stored,
            'filename' => $this->cleanFilename($file->getClientOriginalName(), $extension),
            'mime' => $info['mime'],
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
            'bytes' => (int) $file->getSize(),
            'checksum' => $checksum,
            'alt' => null,
            'created_by' => $userId,
        ]);

        ProcessMedia::dispatch($media->id)->afterCommit();

        return $media;
    }

    /**
     * Cap the original, then write every variant.
     *
     * The queue job's whole body. Capping first means every variant after it
     * decodes a 4000px image at most, whatever was uploaded.
     */
    public function process(Media $media): void
    {
        $max = (int) ($this->config['max_dimension'] ?? 4000);

        $this->withLocalOriginal($media, function (string $path) use ($media, $max): void {
            $resized = $this->processor->constrain($path, $max);

            if ($resized !== null) {
                $media->forceFill([
                    'width' => $resized['width'],
                    'height' => $resized['height'],
                    'bytes' => (int) filesize($path),
                ])->save();
            }
        }, writeBack: true);

        foreach (array_keys($this->presets()) as $preset) {
            foreach ($this->formatsFor($media) as $format) {
                $this->variant($media, $preset, $format);
            }
        }

        $media->forceFill(['processed_at' => now()])->save();
    }

    /**
     * Absolute path to a variant, generating it if it does not exist yet.
     *
     * Idempotent. Two requests for the same missing variant at the same moment
     * both encode it, and the second rename simply replaces the first file
     * with an identical one. That costs one wasted encode in a rare race,
     * which is cheaper than the lock it would take to prevent it.
     */
    public function variant(Media $media, string $preset, string $format): string
    {
        $destination = $this->variantPath($media, $preset, $format);

        if (is_file($destination)) {
            return $destination;
        }

        $definition = $this->preset($preset)
            ?? throw new \InvalidArgumentException("Unknown preset [{$preset}].");

        $size = $this->withLocalOriginal($media, fn (string $path): array => $this->processor->resize(
            source: $path,
            destination: $destination,
            format: $format,
            width: $definition['width'],
            height: $definition['height'],
            focalX: $media->focal_x,
            focalY: $media->focal_y,
            quality: (int) ($this->config['quality'] ?? 80),
        ));

        MediaVariant::query()->upsert(
            [[
                'media_id' => $media->id,
                'preset' => $preset,
                'format' => $format,
                'width' => $size['width'],
                'height' => $size['height'],
                'bytes' => (int) filesize($destination),
                'created_at' => now(),
            ]],
            uniqueBy: ['media_id', 'preset', 'format'],
            update: ['width', 'height', 'bytes', 'created_at'],
        );

        return $destination;
    }

    public function variantPath(Media $media, string $preset, string $format): string
    {
        return public_path(ltrim($media->url($preset, $format), '/'));
    }

    /**
     * Remove every generated file and tell the CDN.
     *
     * Needed whenever the pixels behind a URL change without the URL changing,
     * which is what moving a focal point does. The variant rows say exactly
     * which files exist, so this removes those and nothing else.
     */
    public function purgeVariants(Media $media): void
    {
        $urls = [];

        foreach ($media->variants()->get() as $variant) {
            $urls[] = $media->url($variant->preset, $variant->format);
        }

        File::deleteDirectory(dirname($this->variantPath($media, 'x', 'jpg')));

        $media->variants()->delete();
        $media->forceFill(['processed_at' => null])->save();

        if ($urls !== []) {
            PurgeCdn::dispatch($urls);
        }
    }

    /** Set the focal point and regenerate around it. */
    public function refocus(Media $media, float $x, float $y): void
    {
        $media->forceFill([
            'focal_x' => round(max(0, min(1, $x)), 3),
            'focal_y' => round(max(0, min(1, $y)), 3),
        ])->save();

        $this->purgeVariants($media);

        ProcessMedia::dispatch($media->id)->afterCommit();
    }

    /** The original, its variants and its row. */
    public function delete(Media $media): void
    {
        $this->purgeVariants($media);

        Storage::disk($media->disk)->delete($media->path);

        $media->delete();
    }

    /**
     * Entries that reference an image, anywhere in their data or SEO.
     *
     * A text search over the jsonb rather than a containment query, because a
     * media reference can sit at any depth: a top-level field, a block, a
     * repeater row inside a block. A uuid is long and random enough that a
     * substring match is an exact one in practice.
     *
     * Correct at hundreds of entries and a sequential scan at tens of
     * thousands. If that day comes, this and usageCounts() are the two methods
     * to replace with a usage table maintained by the observer.
     *
     * @return array<int, array{id: int, collection: string, title: string, status: string}>
     */
    public function usage(Media $media): array
    {
        $needle = '%'.$media->uuid.'%';

        return Entry::query()
            ->where(function ($query) use ($needle): void {
                $query->whereRaw('data::text like ?', [$needle])
                    ->orWhereRaw('seo::text like ?', [$needle]);
            })
            ->orderBy('title')
            ->get(['id', 'collection', 'title', 'status'])
            ->map(fn (Entry $entry): array => [
                'id' => $entry->id,
                'collection' => $entry->collection,
                'title' => $entry->title,
                'status' => $entry->status,
            ])
            ->all();
    }

    /**
     * How many entries reference each of a page of images, in one query.
     *
     * @param  array<int, string>  $uuids
     * @return array<string, int>
     */
    public function usageCounts(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($uuids), '(?)'));

        $rows = DB::select(
            "select ids.uuid, count(e.id) as total
               from (values {$placeholders}) as ids(uuid)
               left join entries e
                 on e.deleted_at is null
                and (e.data::text like '%' || ids.uuid || '%'
                     or e.seo::text like '%' || ids.uuid || '%')
              group by ids.uuid",
            $uuids,
        );

        $counts = array_fill_keys($uuids, 0);

        foreach ($rows as $row) {
            $counts[(string) $row->uuid] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * Run a callback against a local copy of the original.
     *
     * GD needs a path. On the local disk that is the file itself; on anything
     * else it is a temporary copy, written back afterwards if the callback
     * changed it.
     *
     * @template T
     *
     * @param  Closure(string): T  $callback
     * @return T
     */
    private function withLocalOriginal(Media $media, Closure $callback, bool $writeBack = false): mixed
    {
        $disk = Storage::disk($media->disk);

        if (config("filesystems.disks.{$media->disk}.driver") === 'local') {
            return $callback($disk->path($media->path));
        }

        $temporary = (string) tempnam(sys_get_temp_dir(), 'cgcms');
        $stream = $disk->readStream($media->path);

        if ($stream === null) {
            throw new \RuntimeException("Original missing for media {$media->uuid}.");
        }

        file_put_contents($temporary, $stream);

        try {
            $result = $callback($temporary);

            if ($writeBack) {
                $disk->put($media->path, (string) file_get_contents($temporary));
            }

            return $result;
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * A filename fit to show in the library.
     *
     * Kept for display and search only. It is never part of a path, so an
     * uploader cannot choose where on disk anything lands.
     */
    private function cleanFilename(string $name, string $extension): string
    {
        $base = Str::limit(pathinfo($name, PATHINFO_FILENAME), 120, '');
        $base = trim((string) preg_replace('/[^\pL\pN._ -]+/u', '-', $base), '-. ');

        return ($base === '' ? 'image' : $base).'.'.$extension;
    }
}
