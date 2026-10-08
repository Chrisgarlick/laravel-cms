<?php

declare(strict_types=1);

namespace Cg\Cms\Http\Controllers\Admin;

use Cg\Cms\Admin\QueueMonitor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The queue: what is waiting, what is running, what failed, and whether a
 * worker is there to do any of it.
 *
 * Retry and forget go through the framework's own queue:retry and
 * queue:forget, so a retried job is pushed exactly as the CLI would push it.
 */
final class QueueController
{
    public function __construct(private readonly QueueMonitor $monitor) {}

    public function index(): Response
    {
        $database = $this->monitor->usesDatabase();

        return Inertia::render('Queue/Index', [
            'driver' => (string) config('queue.default'),
            'supported' => $database,
            'worker' => [
                'running' => $this->monitor->workerRunning(),
                'lastSeenAt' => $this->monitor->lastHeartbeat()?->toIso8601String(),
            ],
            'pending' => $database ? $this->monitor->pending() : [],
            'failed' => $database ? $this->monitor->failed() : [],
            'counts' => $this->monitor->counts(),
        ]);
    }

    public function retry(string $uuid): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return back()->with('success', 'Queued again.');
    }

    public function retryAll(): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => ['all']]);

        return back()->with('success', 'Every failed job was queued again.');
    }

    public function forget(string $uuid): RedirectResponse
    {
        Artisan::call('queue:forget', ['id' => $uuid]);

        return back()->with('success', 'Failed job removed.');
    }

    /**
     * Remove a job that has not started.
     *
     * Only one that is not reserved: deleting a running job's row would not
     * stop it, and the worker would then fail to delete a row that is gone.
     */
    public function cancel(int $id): RedirectResponse
    {
        $deleted = DB::table((string) config('queue.connections.database.table', 'jobs'))
            ->where('id', $id)
            ->whereNull('reserved_at')
            ->delete();

        return back()->with(
            $deleted === 1 ? 'success' : 'error',
            $deleted === 1 ? 'Job cancelled.' : 'That job has already started or finished.',
        );
    }
}
