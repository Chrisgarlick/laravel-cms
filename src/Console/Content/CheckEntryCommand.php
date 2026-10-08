<?php

declare(strict_types=1);

namespace Cg\Cms\Console\Content;

/** Brand voice and SEO checks. Exits 1 if anything would block publishing. */
final class CheckEntryCommand extends ContentCommand
{
    protected $signature = 'cms:check
        {reference : collection/slug or collection/id}
        {--json : Machine-readable output}';

    protected $description = 'Run the brand voice and SEO checks on an entry';

    public function handle(): int
    {
        $blocking = false;

        $code = $this->attempt(function () use (&$blocking): array {
            $checks = $this->service()->checks($this->service()->find((string) $this->argument('reference')));
            $blocking = $checks['blocking'];

            return $checks;
        }, fn (array $checks) => $this->printChecks(['checks' => $checks]));

        return $code === self::SUCCESS && $blocking ? self::FAILURE : $code;
    }
}
