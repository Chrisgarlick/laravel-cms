<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * What the last cms:seo-audit found.
         *
         * Replaced wholesale on every run rather than updated, so an issue
         * that has been fixed disappears on its own. A table of findings that
         * somebody has to close by hand is one that fills with findings that
         * were fixed months ago and stops being read.
         *
         * `entry_id` is set when the page belongs to an entry, so the admin can
         * link straight to the editor. Index pages and site-wide issues have
         * no entry.
         */
        Schema::create('seo_issues', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('url');
            $table->foreignId('entry_id')->nullable()->constrained('entries')->cascadeOnDelete();
            $table->string('rule');
            $table->string('severity');
            $table->text('message');
            $table->text('detail')->nullable();
            $table->timestampTz('found_at');

            $table->index('rule');
            $table->index('entry_id');
        });

        /*
         * Internal links between pages, as of the last audit.
         *
         * Taken from the rendered page rather than from entry data, so links
         * in templates and navigation count. That is what makes "zero links
         * in" mean an orphan: a page reachable from the menu is not one.
         */
        Schema::create('link_graph', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('source');
            $table->string('target');
            $table->unsignedSmallInteger('status')->nullable();
            $table->timestampTz('found_at');

            $table->index('target');
            $table->unique(['source', 'target']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_graph');
        Schema::dropIfExists('seo_issues');
    }
};
