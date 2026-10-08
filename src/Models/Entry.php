<?php

declare(strict_types=1);

namespace Cg\Cms\Models;

use Cg\Cms\Lint\LintIssue;
use Cg\Cms\Schema\Collection as CollectionSchema;
use Cg\Cms\Schema\CollectionRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single piece of content, of any collection.
 *
 * Authored values live in `data`. HTML derived from them lives in `rendered`,
 * computed once on save by the RenderContent observer. Reading an entry for a
 * page view is one indexed row fetch and zero parsing.
 *
 * @property int $id
 * @property string $collection
 * @property string $slug
 * @property string $status
 * @property string $title
 * @property array<string, mixed> $data
 * @property array<string, string> $rendered
 * @property array<string, mixed> $seo
 */
final class Entry extends Model
{
    use SoftDeletes;

    protected $table = 'entries';

    protected $guarded = [];

    /**
     * Defaults for a new model.
     *
     * `data`, `rendered` and `seo` are here because the columns are NOT NULL
     * with a database default, and a database default only applies when the
     * column is left out of the INSERT. Anything that writes an explicit null,
     * such as restoring a revision snapshotted before the entry had any SEO,
     * hits the constraint instead. Defaulting them on the model means the
     * value is always an array by the time anything reads or writes it.
     */
    protected $attributes = [
        'status' => 'draft',
        'locale' => 'en',
        'data' => '{}',
        'rendered' => '{}',
        'seo' => '{}',
    ];

    /**
     * Non-blocking lint findings from the most recent save.
     *
     * A declared property rather than an attribute, deliberately. Assigning an
     * undeclared property on a model goes through __set into the attribute bag,
     * and Eloquent would then try to write a `lint_warnings` column that does
     * not exist. Declaring it keeps the value on the object and out of the
     * INSERT.
     *
     * @var array<int, LintIssue>
     */
    public array $lintWarnings = [];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'rendered' => 'array',
            'seo' => 'array',
            'published_at' => 'immutable_datetime',
            'sort_order' => 'integer',
        ];
    }

    /* -----------------------------------------------------------------------
     | Scopes
     |----------------------------------------------------------------------- */

    public function scopeCollection(Builder $query, string $handle): Builder
    {
        return $query->where('collection', $handle);
    }

    /**
     * Entries carrying a tag slug.
     *
     * Tags are an array of slugs inside `data`, matched by jsonb containment
     * against the existing GIN index. No pivot table and no join, because
     * `entries_data_gin_idx` uses jsonb_path_ops, whose entire purpose is
     * making `@>` fast.
     *
     * Written as a raw predicate on the whole `data` column rather than via
     * whereJsonContains, which generates `data->'tags' @> ...`. That form is
     * an expression on a sub-document and cannot use the top-level index.
     */
    public function scopeTagged(Builder $query, string $tag): Builder
    {
        return $query->whereRaw('data @> ?', [json_encode(['tags' => [$tag]])]);
    }

    /** @param array<int, string> $tags Any of these tags. */
    public function scopeTaggedAny(Builder $query, array $tags): Builder
    {
        return $query->where(function (Builder $q) use ($tags): void {
            foreach ($tags as $tag) {
                $q->orWhereRaw('data @> ?', [json_encode(['tags' => [$tag]])]);
            }
        });
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->where(function (Builder $q): void {
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            });
    }

    /* -----------------------------------------------------------------------
     | Field access
     |----------------------------------------------------------------------- */

    /** An authored field value. */
    public function value(string $field, mixed $fallback = null): mixed
    {
        return data_get($this->data, $field, $fallback);
    }

    /**
     * Pre-rendered HTML for a rich text field.
     *
     * Returns an empty string rather than null so Blade never prints "null",
     * and never falls back to rendering at request time. If the HTML is
     * missing, the save path failed and that is a bug worth seeing as blank.
     */
    public function html(string $field): string
    {
        return (string) data_get($this->rendered, $field, '');
    }

    public function schema(): CollectionSchema
    {
        return app(CollectionRegistry::class)->get($this->collection);
    }

    /* -----------------------------------------------------------------------
     | Routing and caching
     |----------------------------------------------------------------------- */

    public function url(): string
    {
        return $this->schema()->urlFor((string) $this->slug) ?? '/';
    }

    /**
     * True for collections with no public URL of their own.
     *
     * `proof_metric` is the case: its content appears inside other pages, so it
     * has no detail page, needs no slug history and generates no redirects.
     */
    public function routeless(): bool
    {
        return $this->schema()->routePattern() === null;
    }

    /** @return HasMany<EntryRevision> */
    public function revisions(): HasMany
    {
        return $this->hasMany(EntryRevision::class)->orderByDesc('id');
    }

    /**
     * This entry's own identity tag.
     *
     * What a page depends on when it renders *this* entry and nothing else.
     */
    public function cacheTag(): string
    {
        return "entry:{$this->collection}:{$this->id}";
    }

    /**
     * Tags a save on this entry must invalidate.
     *
     * Deliberately broader than cacheTag(). Saving an article changes its own
     * detail page (the entry tag) and also every listing and every page
     * embedding a block that queries articles (the collection tag).
     *
     * The asymmetry matters. If a detail page *registered* both tags, then
     * publishing one article would purge every other article's page too, since
     * they would all carry `collection:article`. Depend on the narrowest thing
     * that is actually true; invalidate the widest thing that might be affected.
     *
     * @return array<int, string>
     */
    public function cacheTags(): array
    {
        $resolver = $this->schema()->cacheTagResolver();

        if ($resolver !== null) {
            return $resolver($this);
        }

        return [
            $this->cacheTag(),
            "collection:{$this->collection}",
        ];
    }
}
