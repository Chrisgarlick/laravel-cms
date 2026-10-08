<?php

declare(strict_types=1);

namespace Cg\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A point-in-time snapshot of an entry.
 *
 * @property int $entry_id
 * @property array<string, mixed> $snapshot
 */
final class EntryRevision extends Model
{
    public $timestamps = false;

    protected $table = 'entry_revisions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    /**
     * Restore this revision onto its entry.
     *
     * Saving through the model rather than updating the row directly, so the
     * observer re-renders rich text and purges the cache. A restore is a
     * content change like any other.
     */
    public function restore(): Entry
    {
        $entry = $this->entry()->firstOrFail();

        $entry->fill($this->normalised());
        $entry->save();

        return $entry;
    }

    /**
     * The snapshot, with nulls removed from columns that cannot take one.
     *
     * `data` and `seo` are NOT NULL with a database default. A snapshot taken
     * of an entry that had no SEO stored a null for it, and filling that back
     * writes an explicit null rather than falling through to the default, so
     * restoring such a revision fails at the constraint.
     *
     * Fixed at the model as well, so new snapshots hold `{}`. This stays for
     * the revisions already in the table, which cannot be fixed retroactively
     * and would otherwise be the ones nobody can restore.
     *
     * @return array<string, mixed>
     */
    private function normalised(): array
    {
        $snapshot = (array) $this->snapshot;

        foreach (['data', 'rendered', 'seo'] as $column) {
            if (array_key_exists($column, $snapshot) && $snapshot[$column] === null) {
                $snapshot[$column] = [];
            }
        }

        return $snapshot;
    }
}
