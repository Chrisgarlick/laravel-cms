<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Form submissions.
 *
 * Stored as well as emailed, deliberately. Email delivery fails, inboxes get
 * archived, and an enquiry that only ever existed as a notification is an
 * enquiry you will eventually lose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_submissions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('form');

            // Which page it came from. For a service enquiry this is the
            // service slug, which is the only attribution that matters here.
            $table->string('context')->nullable();

            $table->jsonb('data');

            // Kept for GDPR requests and abuse handling, not analytics.
            $table->string('ip_hash')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('referrer')->nullable();

            // Populated when the guard rejected it. Rejections are stored
            // rather than discarded: a spike in one reason usually means a
            // check is misfiring on real people.
            $table->string('rejected_for')->nullable();

            $table->timestampTz('notified_at')->nullable();
            $table->timestampsTz();

            $table->index(['form', 'created_at']);
            $table->index('rejected_for');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
    }
};
