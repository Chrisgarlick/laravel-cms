<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cache dependency graph.
 *
 * Every render records which entries and collections it touched. On save we
 * look up exactly which cached URLs depended on the thing that changed and
 * purge only those. This is the difference between "publishing an article
 * purges four URLs" and "publishing an article flushes the site".
 *
 * These tags live in Postgres rather than the cache store on purpose: they must
 * be queryable by tag and must survive a cache flush.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_cache_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('url')->unique();
            $table->string('path');
            $table->unsignedInteger('bytes')->default(0);
            $table->timestampTz('written_at');
        });

        Schema::create('page_cache_tags', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('page_cache_entry_id')
                ->constrained('page_cache_entries')
                ->cascadeOnDelete();
            $table->string('tag');

            $table->unique(['page_cache_entry_id', 'tag']);
            $table->index('tag');
        });

        // Version stamps replace cache tags, which the file store does not
        // support. Bumping one integer makes every listing key for that
        // collection unreachable without needing a tag index in the cache.
        Schema::create('collection_versions', function (Blueprint $table): void {
            $table->string('collection')->primary();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampTz('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_cache_tags');
        Schema::dropIfExists('page_cache_entries');
        Schema::dropIfExists('collection_versions');
    }
};
