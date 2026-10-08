<?php

declare(strict_types=1);

namespace Cg\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $page_cache_entry_id
 * @property string $tag
 */
final class PageCacheTag extends Model
{
    public $timestamps = false;

    protected $table = 'page_cache_tags';

    protected $fillable = ['page_cache_entry_id', 'tag'];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(PageCacheEntry::class, 'page_cache_entry_id');
    }
}
