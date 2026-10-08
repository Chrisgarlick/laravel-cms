<?php

declare(strict_types=1);

namespace Cg\Cms\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * What the queue is doing, read straight from the database driver's tables.
 *
 * Horizon would be the usual answer, but it needs Redis, and this site runs
 * the database queue on a 1GB box. The worker writes a heartbeat (registered
 * by the service provider on Queue::looping) so the admin can say "no worker
 * is running" rather than leaving jobs to sit silently, which is exactly
 * what happens locally, where nothing starts one.
 */
final class QueueMonitor
{
    public const HEARTBEAT_KEY = 'cg-cms:queue-heartbeat';

    /** Seconds without a heartbeat before the worker counts as stopped. */
    private const STALE_AFTER = 90;

    /** Friendly names for the jobs this site runs. Anything else is headlined from its class. */
    private const NAMES = [
        'Cg\Cms\Jobs\ProcessMedia' => 'Make image sizes',
        'Cg\Cms\Jobs\RunSeoAudit' => 'SEO audit',
        'Cg\Cms\Jobs\WarmPageCache' => 'Warm the page cache',
        'Cg\Cms\Jobs\RegenerateSeoFiles' => 'Rebuild sitemap, robots.txt and feeds',
        'Cg\Cms\Jobs\PurgeCdn' => 'Purge the CDN',
        'App\Jobs\RunSiteAudit' => 'Site audit for a lead',
        'App\Jobs\RenderResource' => 'Render a resource PDF',
    ];

    public function usesDatabase(): bool
    {
        return config('queue.default') === 'database';
    }

    public function beat(): void
    {
        // Throttled: the worker loops every few seconds and a cache write on
        // each loop is a write nobody needs.
        if (! Cache::has(self::HEARTBEAT_KEY.':fresh')) {
            Cache::put(self::HEARTBEAT_KEY, now()->toIso8601String(), 3600);
            Cache::put(self::HEARTBEAT_KEY.':fresh', true, 20);
        }
    }

    public function lastHeartbeat(): ?CarbonImmutable
    {
        $value = Cache::get(self::HEARTBEAT_KEY);

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }

    public function workerRunning(): bool
    {
        $beat = $this->lastHeartbeat();

        return $beat !== null && $beat->diffInSeconds(now()) < self::STALE_AFTER;
    }

    /** @return array{pending: int, failed: int} */
    public function counts(): array
    {
        if (! $this->usesDatabase()) {
            return ['pending' => 0, 'failed' => 0];
        }

        try {
            return [
                'pending' => DB::table($this->jobsTable())->count(),
                'failed' => DB::table($this->failedTable())->count(),
            ];
        } catch (Throwable) {
            // The tables may not exist on a fresh install.
            return ['pending' => 0, 'failed' => 0];
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function pending(int $limit = 100): array
    {
        return DB::table($this->jobsTable())
            ->orderBy('available_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (object $job): array => [
                'id' => (int) $job->id,
                'queue' => (string) $job->queue,
                'name' => $this->name((string) $job->payload),
                'class' => $this->className((string) $job->payload),
                'attempts' => (int) $job->attempts,
                'state' => $job->reserved_at !== null ? 'running' : ((int) $job->available_at > time() ? 'delayed' : 'waiting'),
                'availableAt' => CarbonImmutable::createFromTimestamp((int) $job->available_at)->toIso8601String(),
                'createdAt' => CarbonImmutable::createFromTimestamp((int) $job->created_at)->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function failed(int $limit = 100): array
    {
        return DB::table($this->failedTable())
            ->orderByDesc('failed_at')
            ->limit($limit)
            ->get()
            ->map(fn (object $job): array => [
                'id' => (string) $job->uuid,
                'queue' => (string) $job->queue,
                'name' => $this->name((string) $job->payload),
                'class' => $this->className((string) $job->payload),
                'error' => Str::limit(strtok((string) $job->exception, "\n") ?: '', 300),
                'trace' => Str::limit((string) $job->exception, 4000),
                'failedAt' => CarbonImmutable::parse((string) $job->failed_at)->toIso8601String(),
            ])
            ->all();
    }

    /** What a person would call this job. Queued mail says which email. */
    public function name(string $payload): string
    {
        $class = $this->className($payload);

        if ($class === 'Illuminate\Mail\SendQueuedMailable' && preg_match('/O:\d+:"([A-Za-z0-9_\\\\]+\\\\Mail\\\\[A-Za-z0-9_]+)"/', $payload, $match) === 1) {
            return 'Email: '.Str::headline(class_basename(stripslashes($match[1])));
        }

        return self::NAMES[$class] ?? Str::headline(class_basename($class));
    }

    private function className(string $payload): string
    {
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? (string) ($decoded['displayName'] ?? 'Unknown job') : 'Unknown job';
    }

    private function jobsTable(): string
    {
        return (string) config('queue.connections.database.table', 'jobs');
    }

    private function failedTable(): string
    {
        return (string) config('queue.failed.table', 'failed_jobs');
    }
}
