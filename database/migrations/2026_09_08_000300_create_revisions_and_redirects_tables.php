<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A full snapshot per save. Cheap: entries are small and jsonb
        // compresses well, and "restore the version from Tuesday" is worth far
        // more than the disk it costs.
        Schema::create('entry_revisions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('entry_id')->constrained('entries')->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->jsonb('snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at');

            $table->index(['entry_id', 'id']);
        });

        // Slug history. Every slug a published entry has ever had.
        //
        // This exists so that renaming a slug cannot lose the old URL. Losing
        // old URLs on rename is the single largest avoidable source of lost
        // search traffic in hand-managed sites, and it is invisible until the
        // rankings go.
        Schema::create('entry_slugs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('entry_id')->constrained('entries')->cascadeOnDelete();
            $table->string('collection');
            $table->string('slug');
            $table->timestampTz('created_at');

            $table->unique(['collection', 'slug']);
        });

        Schema::create('redirects', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('from')->unique();
            $table->string('to');
            $table->unsignedSmallInteger('status')->default(301);

            // exact | prefix | regex
            $table->string('match_type')->default('exact');

            // Checked before routing, for redirecting a URL that still exists.
            // Everything else resolves on the 404 path, so real routes pay
            // nothing for the redirect table.
            $table->boolean('force')->default(false);

            $table->integer('priority')->default(0);
            $table->unsignedBigInteger('hits')->default(0);
            $table->timestampTz('last_hit_at')->nullable();

            // Set when the redirect was created automatically by a slug change,
            // so a human editing redirects can tell them apart.
            $table->foreignId('entry_id')->nullable()->constrained('entries')->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestampsTz();

            $table->index(['match_type', 'priority']);
            $table->index('force');
        });

        // Unmatched URLs, so a 404 can be turned into a redirect in one click.
        // This is the feature that makes redirect management actually happen
        // rather than being a thing someone means to do.
        Schema::create('not_found_log', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('path')->unique();
            $table->unsignedBigInteger('hits')->default(1);
            $table->string('last_referrer')->nullable();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');

            $table->index('hits');
        });

        DB::statement("comment on table entry_slugs is 'Slug history. Feeds automatic 301s on rename.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('not_found_log');
        Schema::dropIfExists('redirects');
        Schema::dropIfExists('entry_slugs');
        Schema::dropIfExists('entry_revisions');
    }
};
