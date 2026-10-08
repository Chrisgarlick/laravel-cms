<?php

declare(strict_types=1);

namespace Cg\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One generated file in public/.
 *
 * @property int $id
 * @property int $media_id
 * @property string $preset
 * @property string $format
 * @property int $width
 * @property int $height
 * @property int $bytes
 */
final class MediaVariant extends Model
{
    public $timestamps = false;

    protected $table = 'media_variants';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
