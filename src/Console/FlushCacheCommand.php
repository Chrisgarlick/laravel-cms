<?php

declare(strict_types=1);

namespace Cg\Cms\Console;

use Cg\Cms\Cache\CachePurger;
use Illuminate\Console\Command;

final class FlushCacheCommand extends Command
{
    protected $signature = 'cms:flush-cache';

    protected $description = 'Delete every cached page and reset collection version stamps';

    public function handle(CachePurger $purger): int
    {
        $purger->flush();

        $this->components->info('Page cache flushed.');

        return self::SUCCESS;
    }
}
