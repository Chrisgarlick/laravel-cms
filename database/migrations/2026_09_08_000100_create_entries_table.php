<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One table for all content.
 *
 * `data` holds authored field values. `rendered` holds HTML pre-computed on
 * save, so a request never parses a TipTap document. `seo` holds the meta
 * overrides. Anything queried gets promoted to a generated column below.
 *
 * This is deliberately not WordPress's postmeta shape: one indexed row read
 * per entry, not N joins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('collection');
            $table->string('slug');
            $table->string('locale')->default('en');
            $table->string('status')->default('draft');
            $table->string('title');
            $table->timestampTz('published_at')->nullable();
            $table->integer('sort_order')->nullable();
            $table->jsonb('data')->default('{}');
            $table->jsonb('rendered')->default('{}');
            $table->jsonb('seo')->default('{}');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['collection', 'slug', 'locale']);
        });

        // The listing query: published entries of one collection, newest first.
        // Partial index so soft-deleted rows do not bloat it.
        DB::statement('
            create index entries_listing_idx
                on entries (collection, status, published_at desc nulls last)
                where deleted_at is null
        ');

        // Containment queries against authored values. jsonb_path_ops is roughly
        // a third the size of the default and faster for the @> we actually use.
        DB::statement('
            create index entries_data_gin_idx
                on entries using gin (data jsonb_path_ops)
        ');

        // Slug lookups are the hot path for detail pages.
        DB::statement('
            create index entries_slug_lookup_idx
                on entries (collection, slug)
                where deleted_at is null and status = \'published\'
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('entries');
    }
};
