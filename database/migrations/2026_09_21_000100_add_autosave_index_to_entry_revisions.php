<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One autosave revision per entry, enforced by the database.
     *
     * The editor autosaves every fifteen seconds and each autosave replaces
     * the previous one rather than appending: an afternoon's writing would
     * otherwise bury the manual saves somebody actually wants to find.
     *
     * An index rather than application logic, for the reason Phase 0 recorded
     * when concurrent cold hits raced on the page cache. `updateOrCreate` is a
     * select followed by an insert, and two tabs open on one entry is an
     * ordinary thing for one person to do. With this index the write is an
     * upsert, which is atomic.
     *
     * Unique on (entry_id, label) rather than a partial index on entry_id.
     * Both express the rule, but only this one can be inferred by
     * `ON CONFLICT`, which is what Laravel's upsert emits: Postgres matches
     * the conflict target against an index's columns, and a partial index
     * additionally needs its WHERE clause repeated, which the query builder
     * cannot express.
     *
     * Manual revisions are unaffected because their label is null, and
     * Postgres treats nulls as distinct in a unique index. An entry keeps as
     * many unlabelled revisions as it likes and exactly one labelled
     * 'autosave'. That behaviour is load-bearing here, not incidental.
     */
    public function up(): void
    {
        Schema::table('entry_revisions', function (Blueprint $table): void {
            $table->unique(['entry_id', 'label'], 'entry_revisions_entry_label_unique');
        });
    }

    public function down(): void
    {
        Schema::table('entry_revisions', function (Blueprint $table): void {
            $table->dropUnique('entry_revisions_entry_label_unique');
        });
    }
};
