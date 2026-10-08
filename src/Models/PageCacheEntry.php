<?php

declare(strict_types=1);

namespace Cg\Cms\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $url
 * @property string $path
 * @property int $bytes
 */
final class PageCacheEntry extends Model
{
    public $timestamps = false;

    protected $table = 'page_cache_entries';

    protected $fillable = ['url', 'path', 'bytes', 'written_at'];

    protected function casts(): array
    {
        return [
            'written_at' => 'immutable_datetime',
            'bytes' => 'integer',
        ];
    }

    public function tags(): HasMany
    {
        return $this->hasMany(PageCacheTag::class);
    }

    /**
     * Cached URLs that depended on any of the given tags.
     *
     * @param  array<int, string>  $tags
     */
    public function scopeWhereHasAnyTag(Builder $query, array $tags): Builder
    {
        return $query->whereHas('tags', fn (Builder $q) => $q->whereIn('tag', $tags));
    }
}
