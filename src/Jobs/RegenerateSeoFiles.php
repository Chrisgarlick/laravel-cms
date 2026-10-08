<?php

declare(strict_types=1);

namespace Cg\Cms\Jobs;

use Cg\Cms\Seo\SeoFileWriter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Rewrites the sitemap, llms.txt and the feed after content changes.
 *
 * Debounced by being unique for a minute rather than by a hand-rolled cache
 * key. Publishing six entries in a row dispatches one job, not six, and
 * rebuilding these files is a full table scan per collection: cheap once a
 * minute, wasteful once a save.
 *
 * The delay is what makes the debounce useful rather than merely safe. Without
 * it the first save would run immediately and the next five would be dropped,
 * so the files would describe the site as it was before five of the six
 * changes. With it, the window closes after everything has landed.
 *
 * robots.txt is not rewritten here. Its contents depend on config, not content,
 * so regenerating it on every publish would be work that never changes the
 * output.
 */
final class RegenerateSeoFiles implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** One job in flight per minute, however many saves arrive. */
    public int $uniqueFor = 60;

    public int $tries = 2;

    public function handle(SeoFileWriter $writer): void
    {
        try {
            $writer->sitemap();
            $writer->llms();
            $writer->rss();
        } catch (Throwable $e) {
            // A stale sitemap is a small problem. A queue worker crash-looping
            // on every content save is a large one, so this reports and stops.
            report($e);
        }
    }

    public function uniqueId(): string
    {
        return 'cms-seo-files';
    }
}
