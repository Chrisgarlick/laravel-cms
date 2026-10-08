import { Deferred, Head, Link, router, usePage } from '@inertiajs/react'
import { ArrowRight, ArrowRightLeft, ChartColumn, CircleCheck, Image as ImageIcon, Inbox, ListChecks, PenLine, Plus, Zap } from 'lucide-react'
import type { ReactNode } from 'react'
import { Badge, Button, Card, Kbd, Skeleton, StatusBadge, cn } from '@/components/ui'
import { collectionIcon } from '@/layouts/nav'
import { timeAgo } from '@/lib/format'
import type { SharedProps } from '@/types'

interface Props {
    collections: Array<{ handle: string; label: string; published: number; drafts: number }>
    recent?: Array<{ id: number; collection: string; label: string; title: string; status: string; updatedAt: string | null; editor: string | null }>
    seo?: { blocking: number; warnings: number; auditedAt: string | null; topRules: Array<{ rule: string; total: number }> }
    notFound?: Array<{ id: number; path: string; hits: number; lastSeenAt: string | null }>
    submissions?: { accepted: number; rejected: number; unnotified: number; latest: Array<{ id: number; form: string; context: string | null; createdAt: string | null }> }
    media?: { total: number; missingAlt: number }
    cache?: { pages: number; bytes: number; publicPages: number }
}

/**
 * What needs attention, then where you were, then the shape of the site.
 *
 * The attention strip reads the sidebar's shared counts, so it is there on
 * first paint; every panel below is a deferred prop that fills in behind it.
 * Each panel answers "is there anything to do" and links to where it gets
 * done, rather than being a second copy of that screen.
 */
export default function Dashboard({ collections, recent, seo, notFound, submissions, cache }: Props) {
    const { cms, auth, nav } = usePage<SharedProps>().props

    const published = collections.reduce((sum, c) => sum + c.published, 0)
    const drafts = collections.reduce((sum, c) => sum + c.drafts, 0)
    const firstName = auth.user?.name.split(' ')[0] ?? ''
    const primary = collections.find((c) => c.handle === 'article') ?? collections[0]

    const attention: Array<{ key: string; icon: ReactNode; title: string; detail: string; href: string; tone: 'danger' | 'warning' }> = []

    if (nav?.leadsAttention) attention.push({ key: 'leads', icon: <Inbox />, title: 'A lead was never emailed', detail: 'It is saved, but the notification failed. Open it now.', href: `${cms.prefix}/submissions`, tone: 'danger' })
    if ((nav?.queue.failed ?? 0) > 0) attention.push({ key: 'failed', icon: <ListChecks />, title: `${nav?.queue.failed} background ${nav?.queue.failed === 1 ? 'job' : 'jobs'} failed`, detail: 'Something the site tried to do in the background did not happen.', href: `${cms.prefix}/queue`, tone: 'danger' })
    if (nav?.queue.stalled) attention.push({ key: 'stalled', icon: <ListChecks />, title: 'No queue worker is running', detail: `${nav.queue.pending} ${nav.queue.pending === 1 ? 'job is' : 'jobs are'} waiting: emails, image sizes, audits.`, href: `${cms.prefix}/queue`, tone: 'warning' })
    if ((nav?.seoBlocking ?? 0) > 0) attention.push({ key: 'seo', icon: <ChartColumn />, title: `${nav?.seoBlocking} blocking SEO ${nav?.seoBlocking === 1 ? 'issue' : 'issues'}`, detail: 'Found by the last audit.', href: `${cms.prefix}/seo`, tone: 'danger' })
    if ((nav?.mediaMissingAlt ?? 0) > 0) attention.push({ key: 'alt', icon: <ImageIcon />, title: `${nav?.mediaMissingAlt} ${nav?.mediaMissingAlt === 1 ? 'image has' : 'images have'} no alt text`, detail: 'Needed for accessibility and image search.', href: `${cms.prefix}/media?filter=missing-alt`, tone: 'warning' })

    return (
        <>
            <Head title="Dashboard" />

            <header className="mb-8 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p className="text-[12.5px] text-fg-subtle">{new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' })}</p>
                    <h1 className="mt-1 text-[26px] leading-tight font-semibold tracking-[-0.025em]">
                        {greeting()}{firstName ? `, ${firstName}` : ''}
                    </h1>
                </div>
                <div className="flex items-center gap-2">
                    <span className="mr-1 hidden items-center gap-1.5 text-[12px] text-fg-subtle sm:flex">
                        <Kbd>⌘</Kbd><Kbd>K</Kbd> for anything
                    </span>
                    {primary && (
                        <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => router.visit(`${cms.prefix}/${primary.handle}/new`)}>
                            New {primary.handle.replace(/_/g, ' ')}
                        </Button>
                    )}
                </div>
            </header>

            {/* Attention: only what needs doing. */}
            {attention.length > 0 ? (
                <div className="mb-6 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {attention.map((item) => (
                        <Link key={item.key} href={item.href} className={cn('group flex items-start gap-3 rounded-[var(--radius-card)] border p-4 shadow-sm transition-shadow hover:shadow-md', item.tone === 'danger' ? 'border-danger/25 bg-danger-soft' : 'border-warning/25 bg-warning-soft')}>
                            <span className={cn('mt-0.5 [&>svg]:size-4', item.tone === 'danger' ? 'text-danger' : 'text-warning')}>{item.icon}</span>
                            <span className="min-w-0 flex-1">
                                <span className="block text-[13.5px] font-semibold">{item.title}</span>
                                <span className="block text-[12.5px] text-fg-muted">{item.detail}</span>
                            </span>
                            <ArrowRight className="mt-0.5 size-4 text-fg-subtle transition-transform group-hover:translate-x-0.5" />
                        </Link>
                    ))}
                </div>
            ) : (
                <div className="mb-6 flex items-center gap-3 rounded-[var(--radius-card)] border border-success/20 bg-success-soft px-4 py-3 text-[13px]">
                    <CircleCheck className="size-4 text-success" />
                    <span><span className="font-semibold">All clear.</span> <span className="text-fg-muted">No unsent leads, failed jobs, blocking SEO issues or images missing alt text.</span></span>
                </div>
            )}

            {/* Numbers */}
            <div className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Stat label="Published" value={published} hint={`across ${collections.length} collections`} />
                <Stat label="Drafts" value={drafts} hint={drafts === 0 ? 'nothing in progress' : 'not live yet'} />
                <Stat label="Leads this week" value={nav?.leads ?? 0} hint={<Deferred data="submissions" fallback={<span>&nbsp;</span>}><span>{submissions?.rejected ?? 0} spam blocked</span></Deferred>} />
                <Deferred data="cache" fallback={<Card className="p-4"><Skeleton className="h-4 w-20" /><Skeleton className="mt-3 h-7 w-16" /></Card>}>
                    <CacheStat cache={cache} />
                </Deferred>
            </div>

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
                <div className="space-y-6">
                    <Panel title="Pick up where you left off" icon={<PenLine />}>
                        <Deferred data="recent" fallback={<Rows />}>
                            {recent && recent.length === 0 ? (
                                <Empty>Nothing has been edited yet.</Empty>
                            ) : (
                                <ul className="divide-y divide-line">
                                    {recent?.map((entry) => {
                                        const Icon = collectionIcon(entry.collection)

                                        return (
                                            <li key={entry.id}>
                                                <Link href={`${cms.prefix}/${entry.collection}/${entry.id}/edit`} className="group flex items-center gap-3 px-4 py-2.5 hover:bg-hover/60">
                                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border border-line bg-sunken text-fg-subtle"><Icon className="size-4" /></span>
                                                    <span className="min-w-0 flex-1">
                                                        <span className="block truncate text-[13.5px] font-medium group-hover:text-accent">{entry.title || 'Untitled'}</span>
                                                        <span className="block text-[12px] text-fg-subtle">{entry.label}{entry.editor ? ` · ${entry.editor}` : ''} · {timeAgo(entry.updatedAt)}</span>
                                                    </span>
                                                    <StatusBadge status={entry.status} />
                                                </Link>
                                            </li>
                                        )
                                    })}
                                </ul>
                            )}
                        </Deferred>
                    </Panel>

                    <Panel title="Content" icon={<Zap />}>
                        <div className="grid grid-cols-2 gap-px bg-line sm:grid-cols-3">
                            {collections.map((collection) => {
                                const Icon = collectionIcon(collection.handle)

                                return (
                                    <Link key={collection.handle} href={`${cms.prefix}/${collection.handle}`} className="group flex items-center gap-3 bg-panel px-4 py-3 hover:bg-hover/60">
                                        <Icon className="size-4 text-fg-subtle group-hover:text-accent" />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-[13px] font-medium">{collection.label}</span>
                                            <span className="block text-[11.5px] text-fg-subtle tabular-nums">{collection.published} live{collection.drafts > 0 ? ` · ${collection.drafts} draft` : ''}</span>
                                        </span>
                                    </Link>
                                )
                            })}
                        </div>
                    </Panel>
                </div>

                <div className="space-y-6">
                    <Panel title="Latest leads" icon={<Inbox />} href={`${cms.prefix}/submissions`}>
                        <Deferred data="submissions" fallback={<Rows count={3} />}>
                            {submissions && submissions.latest.length === 0 ? (
                                <Empty>No leads yet. They arrive from the contact and audit forms.</Empty>
                            ) : (
                                <ul className="divide-y divide-line">
                                    {submissions?.latest.map((lead) => (
                                        <li key={lead.id} className="flex items-center justify-between gap-3 px-4 py-2.5 text-[13px]">
                                            <span className="min-w-0">
                                                <span className="block truncate font-medium capitalize">{lead.form.replace(/[-_]/g, ' ')}</span>
                                                {lead.context && <span className="block truncate text-[12px] text-fg-subtle">{lead.context}</span>}
                                            </span>
                                            <span className="shrink-0 text-[12px] text-fg-subtle">{timeAgo(lead.createdAt)}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Deferred>
                    </Panel>

                    <Panel title="SEO health" icon={<ChartColumn />} href={`${cms.prefix}/seo`}>
                        <Deferred data="seo" fallback={<Rows count={2} />}>
                            {seo && (seo.auditedAt === null ? (
                                <Empty>No audit has run yet. It runs nightly, or from the SEO screen.</Empty>
                            ) : (
                                <div className="p-4">
                                    <div className="flex gap-6">
                                        <div>
                                            <div className={cn('text-[22px] font-semibold tabular-nums', seo.blocking > 0 ? 'text-danger' : 'text-fg')}>{seo.blocking}</div>
                                            <div className="text-[12px] text-fg-subtle">blocking</div>
                                        </div>
                                        <div>
                                            <div className={cn('text-[22px] font-semibold tabular-nums', seo.warnings > 0 ? 'text-warning' : 'text-fg')}>{seo.warnings}</div>
                                            <div className="text-[12px] text-fg-subtle">warnings</div>
                                        </div>
                                    </div>
                                    {seo.topRules.length > 0 && (
                                        <div className="mt-3 flex flex-wrap gap-1">
                                            {seo.topRules.map((rule) => <Badge key={rule.rule}>{rule.rule} · {rule.total}</Badge>)}
                                        </div>
                                    )}
                                    <p className="mt-3 text-[12px] text-fg-subtle">Last audited {timeAgo(seo.auditedAt)}</p>
                                </div>
                            ))}
                        </Deferred>
                    </Panel>

                    <Panel title="404s worth a redirect" icon={<ArrowRightLeft />} href={`${cms.prefix}/redirects`}>
                        <Deferred data="notFound" fallback={<Rows count={2} />}>
                            {notFound && notFound.length === 0 ? (
                                <Empty>None in the last 30 days.</Empty>
                            ) : (
                                <ul className="divide-y divide-line">
                                    {notFound?.map((miss) => (
                                        <li key={miss.id} className="flex items-center justify-between gap-3 px-4 py-2.5 text-[13px]">
                                            <code className="truncate text-[12px]">{miss.path}</code>
                                            <span className="shrink-0 text-[12px] text-fg-subtle tabular-nums">{miss.hits} hits</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Deferred>
                    </Panel>
                </div>
            </div>
        </>
    )
}

function greeting(): string {
    const hour = new Date().getHours()

    return hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening'
}

function Stat({ label, value, hint }: { label: string; value: number; hint: ReactNode }) {
    return (
        <Card className="p-4">
            <div className="text-[12px] font-medium text-fg-subtle">{label}</div>
            <div className="mt-1.5 text-[26px] leading-none font-semibold tracking-[-0.02em] tabular-nums">{value.toLocaleString('en-GB')}</div>
            <div className="mt-1.5 text-[12px] text-fg-subtle">{hint}</div>
        </Card>
    )
}

function CacheStat({ cache }: { cache: Props['cache'] }) {
    const warm = cache && cache.publicPages > 0 ? Math.min(100, Math.round((cache.pages / cache.publicPages) * 100)) : 0

    return (
        <Card className="p-4">
            <div className="text-[12px] font-medium text-fg-subtle">Page cache</div>
            <div className="mt-1.5 text-[26px] leading-none font-semibold tracking-[-0.02em] tabular-nums">{warm}%</div>
            <div className="mt-2.5 h-1.5 overflow-hidden rounded-full bg-sunken">
                <div className="h-full rounded-full bg-accent transition-[width] duration-700" style={{ width: `${warm}%` }} />
            </div>
            <div className="mt-1.5 text-[12px] text-fg-subtle">{cache?.pages ?? 0} of {cache?.publicPages ?? 0} pages warm</div>
        </Card>
    )
}

function Panel({ title, icon, href, children }: { title: string; icon: ReactNode; href?: string; children: ReactNode }) {
    return (
        <Card className="overflow-hidden">
            <div className="flex items-center justify-between border-b border-line px-4 py-3">
                <h2 className="flex items-center gap-2 text-[13px] font-semibold [&>svg]:size-4 [&>svg]:text-fg-subtle">{icon}{title}</h2>
                {href && (
                    <Link href={href} className="inline-flex items-center gap-1 text-[12px] font-medium text-fg-muted hover:text-fg">
                        View all <ArrowRight className="size-3" />
                    </Link>
                )}
            </div>
            {children}
        </Card>
    )
}

function Rows({ count = 4 }: { count?: number }) {
    return (
        <div className="space-y-3 p-4">
            {Array.from({ length: count }, (_, index) => (
                <div key={index} className="flex items-center gap-3">
                    <Skeleton className="size-8 rounded-lg" />
                    <div className="flex-1 space-y-1.5">
                        <Skeleton className="h-3.5 w-2/3" />
                        <Skeleton className="h-3 w-1/3" />
                    </div>
                </div>
            ))}
        </div>
    )
}

function Empty({ children }: { children: ReactNode }) {
    return <p className="px-4 py-6 text-center text-[13px] text-fg-subtle">{children}</p>
}
