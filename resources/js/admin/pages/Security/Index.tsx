import { Head, router, usePage } from '@inertiajs/react'
import { CircleAlert, CircleCheck, ExternalLink, RefreshCw, ShieldCheck, TriangleAlert } from 'lucide-react'
import { useState } from 'react'
import { Badge, Button, Card, EmptyState, Kbd, PageHeader, Segmented, cn, type Tone } from '@/components/ui'
import { formatDate, timeAgo } from '@/lib/format'
import type { SharedProps } from '@/types'

type Severity = 'critical' | 'high' | 'medium' | 'low' | 'unknown'

interface Advisory {
    ecosystem: 'composer' | 'npm'
    package: string
    installed: string
    dev: boolean
    title: string
    severity: Severity
    affected: string
    cve: string | null
    link: string | null
    reportedAt: string | null
}

interface Report {
    checkedAt: string
    sources: Record<'composer' | 'npm', { packages: number; error: string | null }>
    advisories: Advisory[]
}

const severityLabel: Record<Severity, string> = { critical: 'Critical', high: 'High', medium: 'Medium', low: 'Low', unknown: 'Unrated' }

const severityTone: Record<Severity, Tone> = { critical: 'danger', high: 'danger', medium: 'warning', low: 'neutral', unknown: 'neutral' }

/**
 * Known security advisories against the installed dependencies.
 *
 * A report only. Fixing one means updating the package locally and shipping
 * a release, so each advisory says which command to run on your machine,
 * not on the server. Production dependencies come first: a hole in a build
 * tool never reaches a visitor, so those sit in their own tab.
 */
export default function Index({ report }: { report: Report | null }) {
    const { cms } = usePage<SharedProps>().props
    const [checking, setChecking] = useState(false)
    const advisories = report?.advisories ?? []
    const production = advisories.filter((advisory) => !advisory.dev)
    const development = advisories.filter((advisory) => advisory.dev)
    const [view, setView] = useState<'production' | 'development'>(production.length === 0 && development.length > 0 ? 'development' : 'production')
    const shown = view === 'production' ? production : development

    const check = () => router.post(`${cms.prefix}/security/check`, {}, { preserveScroll: true, onStart: () => setChecking(true), onFinish: () => setChecking(false) })

    return (
        <>
            <Head title="Security" />

            <PageHeader
                title="Security"
                description="Known security advisories against the site's installed packages, checked daily against the same databases composer audit and npm audit use. Updates are made locally and shipped in a release."
                actions={<Button icon={<RefreshCw className={cn('size-3.5', checking && 'animate-spin')} />} onClick={check} disabled={checking}>{checking ? 'Checking' : 'Check now'}</Button>}
            />

            {report === null ? (
                <Card>
                    <EmptyState icon={<ShieldCheck className="size-5" />} title="Not checked yet" description="Run the first check now. After that it runs every night, and the sidebar shows a count if anything turns up." action={<Button onClick={check} disabled={checking}>Check now</Button>} />
                </Card>
            ) : (
                <>
                    <Summary report={report} production={production.length} development={development.length} />

                    {advisories.length > 0 && (
                        <>
                            <div className="mt-6 mb-3">
                                <Segmented<'production' | 'development'>
                                    label="Advisories"
                                    value={view}
                                    onChange={setView}
                                    options={[
                                        { value: 'production', label: 'Used by the site', count: production.length },
                                        { value: 'development', label: 'Development tools', count: development.length },
                                    ]}
                                />
                            </div>

                            {shown.length === 0 ? (
                                <Card><EmptyState icon={<CircleCheck className="size-5 text-success" />} title="Nothing here" description={view === 'production' ? 'No advisory affects a package the live site runs.' : 'No advisory affects a development tool.'} /></Card>
                            ) : (
                                <div className="space-y-3">
                                    {shown.map((advisory, index) => <AdvisoryRow key={`${advisory.package}-${advisory.title}-${index}`} advisory={advisory} />)}
                                </div>
                            )}
                        </>
                    )}
                </>
            )}
        </>
    )
}

function Summary({ report, production, development }: { report: Report; production: number; development: number }) {
    const errors = Object.values(report.sources).map((source) => source.error).filter((error): error is string => error !== null)
    const clean = production + development === 0

    return (
        <div className="space-y-3">
            <div className={cn('flex items-start gap-3 rounded-[var(--radius-card)] border px-4 py-3.5 text-[13px]', clean ? 'border-success/20 bg-success-soft' : production > 0 ? 'border-danger/25 bg-danger-soft' : 'border-warning/30 bg-warning-soft')}>
                {clean ? <CircleCheck className="mt-0.5 size-4 shrink-0 text-success" /> : <TriangleAlert className={cn('mt-0.5 size-4 shrink-0', production > 0 ? 'text-danger' : 'text-warning')} />}
                <div>
                    <p className="font-semibold">
                        {clean
                            ? 'No known advisories.'
                            : production > 0
                              ? `${production} ${production === 1 ? 'advisory affects' : 'advisories affect'} packages the site runs.`
                              : `Only development tools are affected (${development}). Nothing a visitor reaches.`}
                    </p>
                    <p className="mt-0.5 text-fg-muted">
                        Checked {timeAgo(report.checkedAt)} ({formatDate(report.checkedAt)}): {report.sources.composer.packages} PHP and {report.sources.npm.packages} JavaScript packages.
                    </p>
                </div>
            </div>

            {errors.map((error) => (
                <div key={error} className="flex items-start gap-3 rounded-[var(--radius-card)] border border-warning/30 bg-warning-soft px-4 py-3 text-[13px]">
                    <CircleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
                    <p>{error} The other source was still checked; try again in a minute.</p>
                </div>
            ))}
        </div>
    )
}

function AdvisoryRow({ advisory }: { advisory: Advisory }) {
    const command = advisory.ecosystem === 'composer' ? `composer update ${advisory.package} --with-dependencies` : `npm update ${advisory.package}`

    return (
        <Card className="px-4 py-3.5">
            <div className="flex flex-wrap items-center gap-2">
                <Badge tone={severityTone[advisory.severity]} dot>{severityLabel[advisory.severity]}</Badge>
                <code className="text-[13px] font-medium">{advisory.package}</code>
                <span className="text-[12px] text-fg-subtle">{advisory.installed} installed · {advisory.ecosystem === 'composer' ? 'PHP' : 'JavaScript'}</span>
            </div>
            <p className="mt-2 text-[13.5px]">{advisory.title}</p>
            <p className="mt-1 text-[12px] text-fg-subtle">
                Affects {advisory.affected || 'unspecified versions'}
                {advisory.cve && ` · ${advisory.cve}`}
                {advisory.reportedAt && ` · reported ${formatDate(advisory.reportedAt)}`}
            </p>
            <div className="mt-2.5 flex flex-wrap items-center gap-x-4 gap-y-2 text-[12px] text-fg-muted">
                <span>On your machine: <Kbd>{command}</Kbd>, then build and ship a release.</span>
                {advisory.link && (
                    <a href={advisory.link} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-accent hover:underline">
                        Read the advisory <ExternalLink className="size-3" />
                    </a>
                )}
            </div>
        </Card>
    )
}
