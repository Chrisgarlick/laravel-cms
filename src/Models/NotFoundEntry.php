<?php

declare(strict_types=1);

namespace Cg\Cms\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An unmatched URL somebody asked for.
 *
 * Deduplicated by path with a hit count, so a single broken link from a busy
 * site is one row that keeps climbing rather than ten thousand rows.
 *
 * Named NotFoundEntry rather than NotFound because the table it maps to is
 * `not_found_log` and a model called `Log` next to `Entry` in the same
 * namespace would be read as something it is not.
 *
 * @property string $path
 * @property int $hits
 * @property ?string $last_referrer
 */
final class NotFoundEntry extends Model
{
    public $timestamps = false;

    protected $table = 'not_found_log';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'hits' => 'integer',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }
}
