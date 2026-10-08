<?php

declare(strict_types=1);

namespace Cg\Cms\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $from
 * @property string $to
 * @property int $status
 * @property string $match_type
 * @property bool $force
 */
final class Redirect extends Model
{
    protected $table = 'redirects';

    protected $guarded = [];

    protected $attributes = [
        'status' => 301,
        'match_type' => 'exact',
        'force' => false,
        'priority' => 0,
        'hits' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'priority' => 'integer',
            'hits' => 'integer',
            'force' => 'boolean',
            'last_hit_at' => 'immutable_datetime',
        ];
    }

    public function entry()
    {
        return $this->belongsTo(Entry::class);
    }
}
