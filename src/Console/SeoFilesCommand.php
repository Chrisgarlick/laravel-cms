<?php

declare(strict_types=1);

namespace Cg\Cms\Console;

use Cg\Cms\Seo\SeoFileWriter;
use Illuminate\Console\Command;

/**
 * Writes the four machine-readable files to public/.
 *
 * One command rather than four, because the files reference each other:
 * robots.txt points at the sitemap, the sitemap index points at its children,
 * and llms.txt lists the same canonical pages. Regenerating one without the
 * others is how a site ends up advertising a sitemap that describes last
 * month's content.
 *
 * `--only` exists for the cases where that does not matter, such as rewriting
 * robots.txt to take a section out of the index in a hurry.
 *
 * Aliased as cms:sitemap, which is the name section 5.7 of the plan uses.
 */
final class SeoFilesCommand extends Command
{
    protected $signature = 'cms:seo-files
        {--only= : One of sitemap, robots, llms, rss. Defaults to all four}';

    protected $description = 'Write sitemap.xml, robots.txt, llms.txt and the RSS feed to public/';

    /** @var array<int, string> */
    protected $aliases = ['cms:sitemap'];

    public function handle(SeoFileWriter $writer): int
    {
        $only = $this->option('only');

        if ($only !== null && ! in_array($only, ['sitemap', 'robots', 'llms', 'rss'], true)) {
            $this->components->error("Unknown file [{$only}]. Expected sitemap, robots, llms or rss.");

            return self::FAILURE;
        }

        if ($only === null || $only === 'sitemap') {
            $result = $writer->sitemap();

            $this->components->twoColumnDetail(
                'sitemap.xml',
                sprintf('%d URLs across %d file(s)', $result['urls'], count($result['files']) + 1),
            );

            // Named, not counted. A collection silently missing from the
            // sitemap is exactly the kind of thing nobody notices for months.
            if ($result['skipped'] !== []) {
                $this->newLine();
                $this->components->warn(sprintf(
                    'Skipped %d collection(s) with a route pattern but no registered route: %s',
                    count($result['skipped']),
                    implode(', ', $result['skipped']),
                ));
                $this->components->bulletList([
                    'A sitemap must not list URLs that 404, so these are left out until a controller exists.',
                ]);
                $this->newLine();
            }
        }

        if ($only === null || $only === 'robots') {
            $writer->robots();
            $this->components->twoColumnDetail('robots.txt', 'written');
        }

        if ($only === null || $only === 'llms') {
            $writer->llms();
            $this->components->twoColumnDetail('llms.txt', 'written');
        }

        if ($only === null || $only === 'rss') {
            $written = $writer->rss();

            $this->components->twoColumnDetail(
                'rss.xml',
                $written === '' ? '<fg=yellow>skipped, no routable feed collection</>' : 'written',
            );
        }

        return self::SUCCESS;
    }
}
