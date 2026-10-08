<?php

declare(strict_types=1);

namespace Cg\Cms\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An uploaded original.
 *
 * The file this row points at is never served. Everything public is a variant,
 * addressed by uuid and preset, so the original can be replaced, cropped or
 * deleted without any URL a page has already published changing shape.
 *
 * @property int $id
 * @property string $uuid
 * @property string $disk
 * @property string $path
 * @property string $filename
 * @property string $mime
 * @property int $width
 * @property int $height
 * @property int $bytes
 * @property string $checksum
 * @property string|null $alt
 * @property float $focal_x
 * @property float $focal_y
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Media extends Model
{
    protected $table = 'media';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
            'focal_x' => 'float',
            'focal_y' => 'float',
            'processed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** Route model binding by uuid, which is what every URL carries. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return HasMany<MediaVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(MediaVariant::class);
    }

    /**
     * The format a browser with no modern format support gets.
     *
     * PNG for anything that may carry transparency, JPEG for everything else.
     * Flattening a transparent logo onto JPEG's black background is the
     * classic way a media pipeline announces itself on the homepage.
     */
    public function fallbackFormat(): string
    {
        return in_array($this->mime, ['image/png', 'image/gif', 'image/webp'], true) ? 'png' : 'jpg';
    }

    /** Public URL of one variant, whether or not it has been generated yet. */
    public function url(string $preset, ?string $format = null): string
    {
        return sprintf(
            '/%s/%s/%s.%s',
            trim((string) config('cg-cms.media.public_path', 'media'), '/'),
            $this->uuid,
            $preset,
            $format ?? $this->fallbackFormat(),
        );
    }

    public function isMissingAlt(): bool
    {
        return trim((string) $this->alt) === '';
    }
}
