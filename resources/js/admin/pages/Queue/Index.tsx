import { Head, router, usePage } from '@inertiajs/react'
import { ChevronDown, CircleAlert, CircleCheck, Clock, Loader2, RotateCw, Terminal, Trash2, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Badge, Button, Card, Confirm, EmptyState, Kbd, PageHeader, Segmented, Tooltip, cn, toast } from '@/components/ui'
import { timeAgo } from '@/lib/format'
import type { SharedProps } from '@/types'

interface PendingJob { id: number; queue: string; name: string; class: string; attempts: number; state: 'waiting' | 'running' | 'delayed'; availableAt: string; createdAt: string }
interface FailedJob { id: string; queue: string; name: string; class: string; error: string; trace: string; failedAt: string }

interface Props {
    driver: string
    supported: boolean
    worker: { running: boolean; lastSeenAt: string | null }
    pending: PendingJob[]
    failed: FailedJob[]
    counts: { pending: number; failed: number }
}

const WORKER_COMMAND = 'php artisan queue:work'

/**
 * The background work: emails, image sizes, audits, cache warming.
 *
 * The headline is whether a worker is running at all, because a queue with
 * no worker looks exactly like a quiet one: the jobs just wait. The page
 * refreshes itself every few seconds so a job can be watched through.
 */
export default function Index({ driver, supported, worker, pending, failed, counts }: Props) {
    const { cms } = usePage<SharedProps>().props
    const base = `${cms.prefix}/queue`
    const [view, setView] = useState<'pending' | 'failed'>(failed.length > 0 && pending.length === 0 ? 'failed' : 'pending')
    const [cancelling, setCancelling] = useState<PendingJob | null>(null)
    const [forgetting, setForgetting] = useState<FailedJob | null>(null)

    // Live: a quiet partial reload while the page is visible.
    useEffect(() => {
        const timer = setInterval(() => {
            if (document.visibilityState === 'visible') router.reload({ only: ['worker', 'pending', 'failed', 'counts', 'nav'] })
        }, 5000)

        return () => clearInterval(timer)
    }, [])

    const stalled = !worker.running && counts.pending > 0

    return (
        <>
            <Head title="Queue" />

            <PageHeader
                title="Queue"
                description="Work the site does in the background: emails, image sizes, audits and cache warming. Updates every few seconds."
                actions={failed.length > 0 ? <Button icon={<RotateCw className="size-3.5" />} onClick={() => router.post(`${base}/retry-all`, {}, { preserveScroll: true })}>Retry all failed</Button> : undefined}
            />

            {!supported ? (
                <Card>
                    <EmptyState icon={<Terminal className="size-5" />} title={`The queue runs on "${driver}"`} description="This screen reads the database queue's tables. With another driver, use that driver's own tools (Horizon for Redis), or the sync driver runs everything immediately." />
                </Card>
            ) : (
                <>
                    <WorkerStatus running={worker.running} lastSeenAt={worker.lastSeenAt} stalled={stalled} pending={counts.pending} />

                    <div className="mt-6 mb-3">
                        <Segmented<'pending' | 'failed'>
                            label="Jobs"
                            value={view}
                            onChange={setView}
                            options={[
                                { value: 'pending', label: 'Waiting and running', count: counts.pending },
                                { value: 'failed', label: 'Failed', count: counts.failed },
                            ]}
                        />
                    </div>

                    {view === 'pending' ? (
                        pending.length === 0 ? (
                            <Card><EmptyState icon={<CircleCheck className="size-5 text-success" />} title="Nothing waiting" description="Every job has been done. New ones appear here the moment they are queued." /></Card>
                        ) : (
                            <Card className="overflow-hidden">
                                <ul className="divide-y divide-line">
                                    {pending.map((job) => (
                                        <li key={job.id} className="group flex items-center gap-3 px-4 py-3">
                                            <StateIcon state={job.state} />
                                            <div className="min-w-0 flex-1">
                                                <div className="truncate text-[13.5px] font-medium">{job.name}</div>
                                                <div className="truncate text-[12px] text-fg-subtle">
                                                    <code>{job.class}</code> · queued {timeAgo(job.createdAt)}
                                                    {job.state === 'delayed' && ` · starts ${timeAgo(job.availableAt).replace(' ago', '')}`}
                                                    {job.attempts > 0 && ` · attempt ${job.attempts + 1}`}
                                                </div>
                                            </div>
                                            <Badge tone={job.state === 'running' ? 'info' : job.state === 'delayed' ? 'neutral' : 'warning'}>{job.state === 'running' ? 'Running' : job.state === 'delayed' ? 'Scheduled' : 'Waiting'}</Badge>
                                            {job.state !== 'running' && (
                                                <Tooltip content="Cancel">
                                                    <button type="button" onClick={() => setCancelling(job)} className="inline-flex size-7 items-center justify-center rounded-md text-fg-subtle opacity-0 group-hover:opacity-100 hover:bg-hover hover:text-fg focus-visible:opacity-100" aria-label={`Cancel ${job.name}`}>
                                                        <X className="size-3.5" />
                                                    </button>
                                                </Tooltip>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                                {counts.pending > pending.length && <p className="border-t border-line px-4 py-2.5 text-center text-[12px] text-fg-subtle">Showing the first {pending.length} of {counts.pending}.</p>}
                            </Card>
                        )
                    ) : failed.length === 0 ? (
                        <Card><EmptyState icon={<CircleCheck className="size-5 text-success" />} title="No failures" description="When a job fails after its retries, it lands here with the error, ready to retry." /></Card>
                    ) : (
                        <div className="space-y-3">
                            {failed.map((job) => (
                                <FailedRow key={job.id} job={job} onRetry={() => router.post(`${base}/failed/${job.id}/retry`, {}, { preserveScroll: true })} onForget={() => setForgetting(job)} />
                            ))}
                        </div>
                    )}
                </>
            )}

            <Confirm open={cancelling !== null} onOpenChange={(open) => !open && setCancelling(null)} title={`Cancel "${cancelling?.name}"?`} description="It is removed from the queue and will not run. Whatever it was for (an email, an image size) will not happen unless it is triggered again." confirmLabel="Cancel job" danger onConfirm={() => cancelling && router.delete(`${base}/jobs/${cancelling.id}`, { preserveScroll: true })} />
            <Confirm open={forgetting !== null} onOpenChange={(open) => !open && setForgetting(null)} title="Remove this failed job?" description="It will not be retried. Remove it once you have dealt with whatever it failed to do." confirmLabel="Remove" danger onConfirm={() => forgetting && router.delete(`${base}/failed/${forgetting.id}`, { preserveScroll: true })} />
        </>
    )
}

function WorkerStatus({ running, lastSeenAt, stalled, pending }: { running: boolean; lastSeenAt: string | null; stalled: boolean; pending: number }) {
    const copy = () => void navigator.clipboard.writeText(WORKER_COMMAND).then(() => toast('Command copied'), () => toast('Could not copy', { tone: 'error' }))

    if (running) {
        return (
            <div className="flex items-center gap-3 rounded-[var(--radius-card)] border border-success/20 bg-success-soft px-4 py-3 text-[13px]">
                <span className="relative flex size-2.5">
                    <span className="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-60" />
                    <span className="relative inline-flex size-2.5 rounded-full bg-success" />
                </span>
                <span><span className="font-semibold">A worker is running.</span> <span className="text-fg-muted">Last checked in {timeAgo(lastSeenAt)}.</span></span>
            </div>
        )
    }

    return (
        <div className={cn('rounded-[var(--radius-card)] border px-4 py-3.5 text-[13px]', stalled ? 'border-warning/30 bg-warning-soft' : 'border-line bg-panel')}>
            <div className="flex items-start gap-3">
                <CircleAlert className={cn('mt-0.5 size-4 shrink-0', stalled ? 'text-warning' : 'text-fg-subtle')} />
                <div className="min-w-0 flex-1">
                    <p className="font-semibold">{stalled ? `No worker is running, so ${pending} ${pending === 1 ? 'job is' : 'jobs are'} stuck.` : 'No worker is running.'}</p>
                    <p className="mt-0.5 text-fg-muted">
                        {lastSeenAt ? `One was last seen ${timeAgo(lastSeenAt)}. ` : ''}
                        On the server, systemd keeps one running. Locally, start one in a terminal and leave it open:
                    </p>
                    <div className="mt-2.5 flex max-w-md items-center justify-between gap-2 rounded-[var(--radius-control)] border border-line bg-panel py-1 pr-1 pl-3">
                        <code className="text-[12.5px]">{WORKER_COMMAND}</code>
                        <Button size="sm" variant="ghost" onClick={copy}>Copy</Button>
                    </div>
                    <p className="mt-2 text-[12px] text-fg-subtle">Or <Kbd>composer run dev</Kbd>, which starts one alongside Vite.</p>
                </div>
            </div>
        </div>
    )
}

function StateIcon({ state }: { state: PendingJob['state'] }) {
    if (state === 'running') return <Loader2 className="size-4 shrink-0 animate-spin text-info" />
    if (state === 'delayed') return <Clock className="size-4 shrink-0 text-fg-subtle" />

    return <span className="mx-[5px] size-1.5 shrink-0 rounded-full bg-warning" />
}

function FailedRow({ job, onRetry, onForget }: { job: FailedJob; onRetry: () => void; onForget: () => void }) {
    const [open, setOpen] = useState(false)

    return (
        <Card className="overflow-hidden">
            <div className="flex items-start gap-3 px-4 py-3">
                <CircleAlert className="mt-0.5 size-4 shrink-0 text-danger" />
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-baseline gap-x-2">
                        <span className="text-[13.5px] font-medium">{job.name}</span>
                        <span className="text-[12px] text-fg-subtle">failed {timeAgo(job.failedAt)}</span>
                    </div>
                    <p className="mt-1 text-[12.5px] break-words text-danger">{job.error}</p>
                    <button type="button" onClick={() => setOpen((value) => !value)} className="mt-1.5 inline-flex items-center gap-1 text-[12px] text-fg-subtle hover:text-fg">
                        <ChevronDown className={cn('size-3 transition-transform', open && 'rotate-180')} />
                        {open ? 'Hide' : 'Show'} the full error
                    </button>
                </div>
                <div className="flex shrink-0 gap-1">
                    <Button size="sm" icon={<RotateCw className="size-3.5" />} onClick={onRetry}>Retry</Button>
                    <Tooltip content="Remove">
                        <Button size="icon-sm" variant="ghost" onClick={onForget} aria-label="Remove failed job"><Trash2 className="size-3.5" /></Button>
                    </Tooltip>
                </div>
            </div>
            {open && <pre className="max-h-80 overflow-auto border-t border-line bg-sunken px-4 py-3 font-mono text-[11.5px] leading-relaxed whitespace-pre-wrap text-fg-muted">{job.trace}</pre>}
        </Card>
    )
}
