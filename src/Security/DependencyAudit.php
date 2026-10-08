<?php

declare(strict_types=1);

namespace Cg\Cms\Security;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Known security advisories against the site's installed dependencies.
 *
 * Reads what is actually installed from composer.lock and package-lock.json,
 * and asks the same sources `composer audit` and `npm audit` use: Packagist's
 * advisory API, and the npm registry's bulk advisory endpoint. Neither
 * Composer nor npm is needed, which matters on a server that deliberately
 * has neither.
 *
 * Read-only by design: updating stays a local job, followed by a release.
 * The last report is kept in the cache, so the admin screen and the sidebar
 * badge cost nothing until someone asks for a fresh check.
 */
final class DependencyAudit
{
    public const CACHE_KEY = 'cg-cms.security.report';

    private const PACKAGIST = 'https://packagist.org/api/security-advisories/';

    private const NPM = 'https://registry.npmjs.org/-/npm/v1/security/advisories/bulk';

    private const SEVERITY_ORDER = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'unknown' => 4];

    /**
     * Check now, keep the result, and return it.
     *
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $composer = $this->composer();
        $npm = $this->npm();

        $advisories = [...$composer['advisories'], ...$npm['advisories']];
        usort($advisories, fn (array $a, array $b): int => [$a['dev'], self::SEVERITY_ORDER[$a['severity']] ?? 4, $a['package']]
            <=> [$b['dev'], self::SEVERITY_ORDER[$b['severity']] ?? 4, $b['package']]);

        $report = [
            'checkedAt' => now()->toIso8601String(),
            'sources' => [
                'composer' => ['packages' => $composer['packages'], 'error' => $composer['error']],
                'npm' => ['packages' => $npm['packages'], 'error' => $npm['error']],
            ],
            'advisories' => $advisories,
        ];

        Cache::forever(self::CACHE_KEY, $report);

        return $report;
    }

    /**
     * The last report, or null if no check has run.
     *
     * @return array<string, mixed>|null
     */
    public function latest(): ?array
    {
        $report = Cache::get(self::CACHE_KEY);

        return is_array($report) ? $report : null;
    }

    /** Advisories against what runs in production (not dev tools), from the last report. */
    public function productionCount(): int
    {
        return count(array_filter($this->latest()['advisories'] ?? [], fn (array $advisory): bool => ! $advisory['dev']));
    }

    /**
     * @return array{packages: int, advisories: array<int, array<string, mixed>>, error: ?string}
     */
    private function composer(): array
    {
        $lock = $this->json(base_path('composer.lock'));

        if ($lock === null) {
            return ['packages' => 0, 'advisories' => [], 'error' => 'composer.lock was not found.'];
        }

        $installed = [];

        foreach (['packages' => false, 'packages-dev' => true] as $section => $dev) {
            foreach ($lock[$section] ?? [] as $package) {
                $version = (string) ($package['version'] ?? '');

                // A branch (dev-main) has no release a range could name.
                if (! isset($package['name']) || $version === '' || str_starts_with($version, 'dev-')) {
                    continue;
                }

                $installed[$package['name']] = ['version' => $version, 'dev' => $dev];
            }
        }

        try {
            $advisories = [];

            foreach (array_chunk(array_keys($installed), 100) as $names) {
                $response = Http::timeout(20)->acceptJson()->asForm()->post(self::PACKAGIST, ['packages' => $names])->throw();

                foreach ((array) $response->json('advisories', []) as $name => $list) {
                    // Only the packages this request asked about.
                    if (! in_array($name, $names, true)) {
                        continue;
                    }

                    foreach ((array) $list as $advisory) {
                        $package = $installed[$name] ?? null;

                        if ($package === null || ! VersionRange::matches($package['version'], (string) ($advisory['affectedVersions'] ?? ''))) {
                            continue;
                        }

                        $advisories[] = [
                            'ecosystem' => 'composer',
                            'package' => (string) $name,
                            'installed' => $package['version'],
                            'dev' => $package['dev'],
                            'title' => (string) ($advisory['title'] ?? 'Security advisory'),
                            'severity' => $this->severity($advisory['severity'] ?? null),
                            'affected' => (string) ($advisory['affectedVersions'] ?? ''),
                            'cve' => $advisory['cve'] ?? null,
                            'link' => $advisory['link'] ?? null,
                            'reportedAt' => $advisory['reportedAt'] ?? null,
                        ];
                    }
                }
            }

            return ['packages' => count($installed), 'advisories' => $advisories, 'error' => null];
        } catch (Throwable $e) {
            return ['packages' => count($installed), 'advisories' => [], 'error' => 'Packagist could not be reached: '.$e->getMessage()];
        }
    }

    /**
     * @return array{packages: int, advisories: array<int, array<string, mixed>>, error: ?string}
     */
    private function npm(): array
    {
        $lock = $this->json(base_path('package-lock.json'));

        if ($lock === null) {
            return ['packages' => 0, 'advisories' => [], 'error' => null];
        }

        /** @var array<string, array{versions: array<string, true>, dev: bool}> $installed */
        $installed = [];

        // lockfileVersion 2 and 3: "node_modules/a/node_modules/b" => {version, dev}.
        foreach ((array) ($lock['packages'] ?? []) as $path => $package) {
            if (! is_string($path) || ! str_contains($path, 'node_modules/') || ! isset($package['version'])) {
                continue;
            }

            $name = substr($path, strrpos($path, 'node_modules/') + strlen('node_modules/'));
            $installed[$name]['versions'][(string) $package['version']] = true;
            $installed[$name]['dev'] = ($installed[$name]['dev'] ?? true) && (bool) ($package['dev'] ?? false);
        }

        if ($installed === []) {
            return ['packages' => 0, 'advisories' => [], 'error' => null];
        }

        try {
            $query = array_map(fn (array $package): array => array_keys($package['versions']), $installed);
            $response = Http::timeout(20)->acceptJson()->post(self::NPM, $query)->throw();
            $advisories = [];

            // The registry has already matched the versions sent.
            foreach ((array) $response->json() as $name => $list) {
                foreach ((array) $list as $advisory) {
                    $advisories[] = [
                        'ecosystem' => 'npm',
                        'package' => (string) $name,
                        'installed' => implode(', ', array_keys($installed[$name]['versions'] ?? [])),
                        'dev' => $installed[$name]['dev'] ?? true,
                        'title' => (string) ($advisory['title'] ?? 'Security advisory'),
                        'severity' => $this->severity($advisory['severity'] ?? null),
                        'affected' => (string) ($advisory['vulnerable_versions'] ?? ''),
                        'cve' => null,
                        'link' => $advisory['url'] ?? null,
                        'reportedAt' => null,
                    ];
                }
            }

            return ['packages' => count($installed), 'advisories' => $advisories, 'error' => null];
        } catch (Throwable $e) {
            return ['packages' => count($installed), 'advisories' => [], 'error' => 'The npm registry could not be reached: '.$e->getMessage()];
        }
    }

    private function severity(mixed $severity): string
    {
        $severity = strtolower((string) $severity);

        return match ($severity) {
            'critical', 'high', 'medium', 'low' => $severity,
            'moderate' => 'medium',
            default => 'unknown',
        };
    }

    /** @return array<string, mixed>|null */
    private function json(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
