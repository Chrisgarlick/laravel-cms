<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Editable settings, overlaid on config.
     *
     * Everything here has a config default, and a row exists only where
     * somebody has changed something. That is what keeps a fresh install
     * working with an empty table, and it means deleting a row is how you
     * revert to the shipped value rather than a way to break the site.
     *
     * jsonb rather than a string column, so a setting can be a list (social
     * profiles) or a flag without the store having to encode and guess types
     * on the way back out.
     */
    public function up(): void
    {
        Schema::create('cms_settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->jsonb('value');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_settings');
    }
};
