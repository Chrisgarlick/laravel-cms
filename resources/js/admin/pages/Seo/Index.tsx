import { Head, Link, router, usePage } from '@inertiajs/react'
import { ChartColumn, CircleCheck, ExternalLink, FileText, Play } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Badge, Button, Card, EmptyState, PageHeader, cn } from '@/components/ui'
import { timeAgo } from '@/lib/format'
import type { SharedProps } from '@/types'

interface Issue {
    id: number
    url: string
    rule: string
    severity: 'block' | 'warn'
    message: string
    detail: string | null
    entry: { id: number; collection: string; title: string } | null
}

interface Props {
    rule: string | null
    summary: Array<{ rule: string; severity: 'block' | 'warn'; total: number }>
    issues: Issue[]
    total: number
    limit: number
    auditedAt: string | null
}

/**
 * What the last audit found, grouped by page, each linked to where it gets
 * fixed.
 *
 * Nothing is closed by hand. Each run replaces the list, so fixing the page
 * is how an issue goes away, and the list is always the site as it is now.
 */
export default function Index({ rule, summary, issues, total, limit, auditedAt }: Props) {
    const { cms } = usePage<SharedProps>().props
    const base = `${cms.prefix}/seo`
    const [running, setRunning] = useState(false)

    const blocking = summary.filter((row) => row.severity === 'block').reduce((sum, row) => sum + row.total, 0)
    const warnings = summary.filter((row) => row.severity === 'warn').reduce((sum, row) => sum + row.total, 0)

    // One card per page, blocking issues first.
    const pages = useMemo(() => {
        const grouped = new Map<string, { url: string; entry: Issue['entry']; issues: Issue[] }>()

        for (const issue of issues) {
            const page = grouped.get(issue.url) ?? { url: issue.url, entry: issue.entry, issues: [] }
            page.issues.push(issue)
            grouped.set(issue.url, page)
        }

        return [...grouped.values()]
            .map((page) => ({ ...page, issues: page.issues.sort((a, b) => (a.severity === b.severity ? 0 : a.severity === 'block' ? -1 : 1)) }))
            .sort((a, b) => Number(b.issues.some((i) => i.severity === 'block')) - Number(a.issues.some((i) => i.severity === 'block')) || b.issues.length - a.issues.length)
    }, [issues])

    const run = () => {
        setRunning(true)
        router.post(`${base}/audit`, {}, { preserveScroll: true, onFinish: () => setRunning(false) })
    }

    return (
        <>
            <Head title="SEO" />

            <PageHeader
                title="SEO"
                description={auditedAt === null ? 'No audit has run yet. It runs every night, or now.' : `Last audited ${timeAgo(auditedAt)}. Runs every night.`}
                actions={<Button variant="primary" icon={<Play className="size-3.5" />} loading={running} onClick={run}>Run audit now</Button>}
            />

            <div className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Card className="p-4">
                    <div className="text-[12px] font-medium text-fg-subtle">Blocking</div>
                    <div className={cn('mt-1.5 text-[26px] leading-none font-semibold tabular-nums', blocking > 0 && 'text-danger')}>{blocking}</div>
                    <div className="mt-1.5 text-[12px] text-fg-subtle">fix before publishing more</div>
                </Card>
                <Card className="p-4">
                    <div className="text-[12px] font-medium text-fg-subtle">Warnings</div>
                    <div className={cn('mt-1.5 text-[26px] leading-none font-semibold tabular-nums', warnings > 0 && 'text-warning')}>{warnings}</div>
                    <div className="mt-1.5 text-[12px] text-fg-subtle">worth a look</div>
                </Card>
                <Card className="p-4">
                    <div className="text-[12px] font-medium text-fg-subtle">Pages affected</div>
                    <div className="mt-1.5 text-[26px] leading-none font-semibold tabular-nums">{pages.length}{total > issues.length ? '+' : ''}</div>
                    <div className="mt-1.5 text-[12px] text-fg-subtle">{rule ? `for ${rule}` : 'across every rule'}</div>
                </Card>
                <Card className="p-4">
                    <div className="text-[12px] font-medium text-fg-subtle">Rules triggered</div>
                    <div className="mt-1.5 text-[26px] leading-none font-semibold tabular-nums">{summary.length}</div>
                    <div className="mt-1.5 text-[12px] text-fg-subtle">of the checks run</div>
                </Card>
            </div>

            {summary.length > 0 && (
                <div className="mb-4 flex flex-wrap gap-1.5" role="tablist" aria-label="Filter by rule">
                    <RuleChip active={rule === null} onClick={() => router.get(base, {}, { preserveScroll: true })}>All <span className="text-fg-subtle">{blocking + warnings}</span></RuleChip>
                    {summary.map((row) => (
                        <RuleChip key={`${row.rule}-${row.severity}`} active={rule === row.rule} onClick={() => router.get(base, { rule: row.rule }, { preserveScroll: true })}>
                            <span className={cn('size-1.5 rounded-full', row.severity === 'block' ? 'bg-danger' : 'bg-warning')} />
                            {row.rule} <span className="text-fg-subtle tabular-nums">{row.total}</span>
                        </RuleChip>
                    ))}
                </div>
            )}

            {pages.length === 0 ? (
                <Card>
                    {auditedAt === null ? (
                        <EmptyState icon={<ChartColumn className="size-5" />} title="No audit yet" description="The audit renders every public page and checks titles, descriptions, headings, links, images and structured data." action={<Button variant="primary" icon={<Play className="size-3.5" />} loading={running} onClick={run}>Run the first audit</Button>} />
                    ) : (
                        <EmptyState icon={<CircleCheck className="size-5 text-success" />} title="Nothing found" description="Every page passed every check in the last audit." />
                    )}
                </Card>
            ) : (
                <div className="space-y-3">
                    {pages.map((page) => (
                        <Card key={page.url} className="overflow-hidden">
                            <div className="flex items-center justify-between gap-3 border-b border-line bg-sunken/40 px-4 py-2.5">
                                <div className="flex min-w-0 items-center gap-2.5">
                                    <FileText className="size-4 shrink-0 text-fg-subtle" />
                                    {page.entry ? (
                                        <Link href={`${cms.prefix}/${page.entry.collection}/${page.entry.id}/edit`} className="truncate text-[13.5px] font-medium hover:text-accent">{page.entry.title}</Link>
                                    ) : (
                                        <span className="truncate text-[13.5px] font-medium">{page.url}</span>
                                    )}
                                    <code className="hidden truncate text-[11.5px] text-fg-subtle sm:inline">{page.url}</code>
                                </div>
                                <a href={page.url} target="_blank" rel="noreferrer" className="inline-flex size-7 shrink-0 items-center justify-center rounded-md text-fg-subtle hover:bg-hover hover:text-fg" aria-label={`Open ${page.url}`}>
                                    <ExternalLink className="size-3.5" />
                                </a>
                            </div>
                            <ul className="divide-y divide-line">
                                {page.issues.map((issue) => (
                                    <li key={issue.id} className="flex items-start gap-3 px-4 py-3">
                                        <span className={cn('mt-1.5 size-1.5 shrink-0 rounded-full', issue.severity === 'block' ? 'bg-danger' : 'bg-warning')} aria-label={issue.severity === 'block' ? 'Blocking' : 'Warning'} />
                                        <div className="min-w-0 flex-1">
                                            <p className="text-[13px]">{issue.message}</p>
                                            {issue.detail && <p className="mt-0.5 text-[12px] break-words text-fg-subtle">{issue.detail}</p>}
                                        </div>
                                        <Badge tone={issue.severity === 'block' ? 'danger' : 'warning'} className="shrink-0 font-mono !text-[11px]">{issue.rule}</Badge>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    ))}
                </div>
            )}

            {total > issues.length && <p className="mt-4 text-center text-[12.5px] text-fg-subtle">Showing the first {limit} of {total} issues. Filter by rule to see the rest.</p>}
        </>
    )
}

function RuleChip({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <button
            type="button"
            role="tab"
            aria-selected={active}
            onClick={onClick}
            className={cn('inline-flex h-7 items-center gap-1.5 rounded-full border px-3 text-[12.5px] font-medium transition-colors', active ? 'border-fg bg-fg text-canvas [&_.text-fg-subtle]:text-canvas/60' : 'border-line bg-panel text-fg-muted hover:border-line-strong hover:text-fg')}
        >
            {children}
        </button>
    )
}
