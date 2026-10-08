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
         * One row per uploaded original.
         *
         * The uuid, not the id, is what appears in URLs and in entry data. An
         * incrementing id in a public URL tells anyone how many images exist
         * and invites walking them, and an id stored in jsonb would make
         * moving content between two installs renumber every reference.
         *
         * The checksum is unique so uploading the same file twice returns the
         * existing row instead of storing a second copy with a second set of
         * variants. That happens far more than it sounds, usually from
         * dragging a whole folder in again after one file failed.
         */
        Schema::create('media', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('disk');
            $table->string('path');
            $table->string('filename');
            $table->string('mime');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedBigInteger('bytes');
            $table->char('checksum', 64)->unique();

            // The library default. An entry can still say something different
            // about the same image in context, see MediaValue.
            $table->string('alt')->nullable();

            // 0 to 1 on each axis, centre by default. Crops keep this point in
            // frame, so a portrait cropped to a wide card keeps the face.
            $table->decimal('focal_x', 4, 3)->default(0.5);
            $table->decimal('focal_y', 4, 3)->default(0.5);

            // Set once every configured variant has been written. Until then
            // the image works, but its first visitors pay for the encoding.
            $table->timestampTz('processed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('created_at');
        });

        /*
         * One row per file written to public/.
         *
         * Recorded rather than inferred from the directory, so deleting an
         * image or changing its focal point removes exactly the files that
         * exist, and cache busting can name every URL that needs purging.
         */
        Schema::create('media_variants', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->string('preset');
            $table->string('format');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedBigInteger('bytes');
            $table->timestampTz('created_at');

            $table->unique(['media_id', 'preset', 'format']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_variants');
        Schema::dropIfExists('media');
    }
};
