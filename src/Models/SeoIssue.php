<?php

declare(strict_types=1);

namespace Cg\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One finding from the last cms:seo-audit run.
 *
 * @property int $id
 * @property string $url
 * @property int|null $entry_id
 * @property string $rule
 * @property string $severity
 * @property string $message
 * @property string|null $detail
 */
final class SeoIssue extends Model
{
    public $timestamps = false;

    protected $table = 'seo_issues';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'found_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }
}
