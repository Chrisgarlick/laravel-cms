<?php

declare(strict_types=1);

namespace Cg\Cms\Console;

use Cg\Cms\Security\DependencyAudit;
use Illuminate\Console\Command;

/**
 * Check the installed dependencies against known security advisories.
 *
 * Scheduled daily by the package (cg-cms.security.schedule), so the admin's
 * Security screen and its sidebar badge are never more than a day old. It
 * reports only: updating is done locally and shipped in a release.
 */
final class SecurityAuditCommand extends Command
{
    protected $signature = 'cms:security-audit';

    protected $description = 'Check composer.lock and package-lock.json against known security advisories';

    public function handle(DependencyAudit $audit): int
    {
        $report = $audit->run();

        foreach ($report['sources'] as $source => $result) {
            $this->components->twoColumnDetail(ucfirst($source).' packages', (string) $result['packages']);

            if ($result['error'] !== null) {
                $this->components->warn($result['error']);
            }
        }

        if ($report['advisories'] === []) {
            $this->components->info('No known advisories.');

            return self::SUCCESS;
        }

        $this->table(
            ['Severity', 'Package', 'Installed', 'Advisory', 'Used in'],
            array_map(fn (array $advisory): array => [
                $advisory['severity'],
                $advisory['package'],
                $advisory['installed'],
                $advisory['title'],
                $advisory['dev'] ? 'development only' : 'production',
            ], $report['advisories']),
        );

        return self::FAILURE;
    }
}
