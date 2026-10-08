<?php

declare(strict_types=1);

namespace Cg\Cms\Media;

use Cg\Cms\Models\Media;

/**
 * Turns a stored media value into an image a template can emit.
 *
 * Entries store `{id, alt}`: a reference to the library and what this image
 * means here. Not a URL. A URL in entry data goes stale the moment a preset
 * changes, and changing a preset is a config edit nobody would expect to need
 * a content migration.
 *
 * The older `{src, alt}` shape still resolves, unchanged, because that is what
 * the Phase 6 import writes for images that predate the library and what
 * entries saved before this step already hold.
 *
 * Scoped to the request, with a memo, so a listing page with twenty cards
 * reads each image once rather than once per use.
 */
final class MediaResolver
{
    /** @var array<string, Media|null> */
    private array $loaded = [];

    public function __construct(private readonly MediaLibrary $library) {}

    /** The library uuid a value points at, if it points at one. */
    public static function reference(mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        $id = $value['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Load several references in one query, ahead of resolving them.
     *
     * @param  array<int, string>  $uuids
     */
    public function prime(array $uuids): void
    {
        $missing = array_values(array_diff(array_unique($uuids), array_keys($this->loaded)));

        if ($missing === []) {
            return;
        }

        $found = Media::query()->whereIn('uuid', $missing)->get()->keyBy('uuid');

        foreach ($missing as $uuid) {
            $this->loaded[$uuid] = $found->get($uuid);
        }
    }

    public function resolve(mixed $value, string $preset): ?ResolvedImage
    {
        if (! is_array($value)) {
            return null;
        }

        $alt = trim((string) ($value['alt'] ?? ''));
        $uuid = self::reference($value);

        if ($uuid === null) {
            $src = $value['src'] ?? null;

            return is_string($src) && $src !== ''
                ? new ResolvedImage(src: $src, alt: $alt)
                : null;
        }

        $this->prime([$uuid]);
        $media = $this->loaded[$uuid] ?? null;

        // A reference to an image that has since been deleted renders nothing
        // rather than a broken image. Deleting an image in use is refused in
        // the admin, so this is the path for one removed some other way.
        if ($media === null) {
            return null;
        }

        // An unknown preset falls back to `full` rather than emitting a URL the
        // variant route will 404. A typo in a template should cost a larger
        // file, not a missing image.
        if ($this->library->preset($preset) === null) {
            $preset = 'full';
        }

        $definition = $this->library->preset($preset);

        if ($definition === null) {
            return null;
        }

        $size = CropGeometry::output($media->width, $media->height, $definition['width'], $definition['height']);

        $sources = [];

        foreach ($this->library->modernFormats() as $format) {
            $sources['image/'.$format] = $media->url($preset, $format);
        }

        return new ResolvedImage(
            src: $media->url($preset),

            // The entry's alt first: the same photo can mean different things
            // on two pages. The library's alt is the fallback for an entry
            // that has not said anything about it.
            alt: $alt !== '' ? $alt : trim((string) $media->alt),
            width: $size['width'],
            height: $size['height'],
            sources: $sources,
        );
    }
}
