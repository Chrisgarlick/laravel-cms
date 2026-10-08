import { Head, router, useForm, usePage } from '@inertiajs/react'
import { ArrowRight, Download, Link2Off, Plus, Search, Trash2, Upload, Wand2 } from 'lucide-react'
import { useState } from 'react'
import { Badge, Button, Card, Confirm, Dialog, EmptyState, PageHeader, Segmented, Tooltip, cn } from '@/components/ui'
import { timeAgo } from '@/lib/format'
import type { SharedProps } from '@/types'

interface Rule {
    id: number
    from: string
    to: string
    status: number
    matchType: string
    force: boolean
    hits: number
    lastHitAt: string | null
    notes: string | null
    automatic: boolean
}

interface Miss {
    id: number
    path: string
    hits: number
    lastReferrer: string | null
    lastSeenAt: string | null
}

interface Props {
    tab: 'redirects' | 'not-found'
    redirects: Rule[]
    notFound: Miss[]
}

/**
 * Redirects, and the 404s that should become them.
 *
 * One screen, two views, because it is one task: the reason to log unmatched
 * URLs at all is that turning one into a redirect should be a click from
 * where you noticed it.
 */
export default function Index({ tab, redirects, notFound }: Props) {
    const { cms } = usePage<SharedProps>().props
    const base = `${cms.prefix}/redirects`
    const [creating, setCreating] = useState(false)

    return (
        <>
            <Head title="Redirects" />

            <PageHeader
                title="Redirects"
                description="Old URLs pointed at new ones. Renaming a slug adds one automatically."
                actions={
                    <>
                        <a className="button-quiet" href={`${base}/export`}><Download className="size-3.5" />Export</a>
                        <ImportButton url={`${base}/import`} />
                        <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>New redirect</Button>
                    </>
                }
            />

            <div className="mb-4">
                <Segmented<'redirects' | 'not-found'>
                    label="View"
                    value={tab}
                    onChange={(value) => router.get(base, value === 'redirects' ? {} : { tab: value }, { preserveScroll: true })}
                    options={[
                        { value: 'redirects', label: 'Redirects', count: redirects.length },
                        { value: 'not-found', label: '404s', count: notFound.length },
                    ]}
                />
            </div>

            {tab === 'redirects' ? <RedirectsView rules={redirects} base={base} onCreate={() => setCreating(true)} /> : <NotFoundView misses={notFound} base={base} />}

            <CreateDialog open={creating} onOpenChange={setCreating} base={base} />
        </>
    )
}

function RedirectsView({ rules, base, onCreate }: { rules: Rule[]; base: string; onCreate: () => void }) {
    const [filter, setFilter] = useState('')
    const [deleting, setDeleting] = useState<Rule | null>(null)
    const needle = filter.trim().toLowerCase()
    const shown = needle === '' ? rules : rules.filter((rule) => rule.from.toLowerCase().includes(needle) || rule.to.toLowerCase().includes(needle))

    if (rules.length === 0) {
        return (
            <Card>
                <EmptyState icon={<ArrowRight className="size-5" />} title="No redirects yet" description="Add one by hand, import a CSV, or turn a 404 into one." action={<Button variant="primary" icon={<Plus className="size-4" />} onClick={onCreate}>New redirect</Button>} />
            </Card>
        )
    }

    return (
        <>
            <div className="relative mb-3 w-full sm:w-72">
                <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-fg-subtle" />
                <input
                    type="search"
                    value={filter}
                    onChange={(event) => setFilter(event.target.value)}
                    placeholder="Filter by path"
                    className="h-8 w-full rounded-[var(--radius-control)] border border-line bg-panel pr-2 pl-8 text-[13px] shadow-sm placeholder:text-fg-subtle focus:border-accent focus:ring-3 focus:ring-[var(--accent-ring)] focus:outline-none"
                />
            </div>

            <Card className="overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[720px] text-[13px]">
                        <thead>
                            <tr className="border-b border-line bg-sunken/60 text-left text-[11.5px] text-fg-subtle">
                                <th className="py-2 pl-4 font-medium">From</th>
                                <th className="py-2 pr-4 font-medium">To</th>
                                <th className="py-2 pr-4 font-medium">Type</th>
                                <th className="py-2 pr-4 font-medium">Used</th>
                                <th className="w-12" />
                            </tr>
                        </thead>
                        <tbody>
                            {shown.map((rule) => (
                                <tr key={rule.id} className="group border-b border-line last:border-b-0 hover:bg-hover/50">
                                    <td className="max-w-[280px] py-2.5 pl-4">
                                        <code className="block truncate text-[12.5px]" title={rule.from}>{rule.from}</code>
                                        {rule.automatic && <span className="text-[11.5px] text-fg-subtle" title={rule.notes ?? ''}>Added when a slug changed</span>}
                                    </td>
                                    <td className="max-w-[280px] py-2.5 pr-4">
                                        <span className="flex items-center gap-2">
                                            <ArrowRight className="size-3.5 shrink-0 text-fg-subtle" />
                                            {rule.status === 410 ? <Badge tone="danger">Gone</Badge> : <code className="truncate text-[12.5px]" title={rule.to}>{rule.to}</code>}
                                        </span>
                                    </td>
                                    <td className="py-2.5 pr-4">
                                        <span className="flex flex-wrap gap-1">
                                            <Badge tone={rule.status === 301 ? 'neutral' : rule.status === 302 ? 'info' : 'danger'}>{rule.status}</Badge>
                                            {rule.matchType !== 'exact' && <Badge tone="accent">{rule.matchType}</Badge>}
                                            {rule.force && <Badge tone="warning">forced</Badge>}
                                        </span>
                                    </td>
                                    <td className="py-2.5 pr-4 text-fg-muted">
                                        {rule.hits === 0 ? (
                                            <span className="text-fg-subtle">Never</span>
                                        ) : (
                                            <span className="tabular-nums">{rule.hits.toLocaleString('en-GB')} <span className="text-fg-subtle">· {timeAgo(rule.lastHitAt)}</span></span>
                                        )}
                                    </td>
                                    <td className="py-2.5 pr-3 text-right">
                                        {!rule.automatic && (
                                            <Tooltip content="Delete">
                                                <button type="button" onClick={() => setDeleting(rule)} className="inline-flex size-7 items-center justify-center rounded-md text-fg-subtle opacity-0 group-hover:opacity-100 hover:bg-danger-soft hover:text-danger focus-visible:opacity-100" aria-label={`Delete ${rule.from}`}>
                                                    <Trash2 className="size-3.5" />
                                                </button>
                                            </Tooltip>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {shown.length === 0 && <p className="px-4 py-8 text-center text-[13px] text-fg-subtle">Nothing matches “{filter}”.</p>}
            </Card>

            <Confirm
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title="Delete this redirect?"
                description={deleting ? <>Anyone visiting <code>{deleting.from}</code> will get a 404 instead.</> : undefined}
                confirmLabel="Delete"
                danger
                onConfirm={() => deleting && router.delete(`${base}/${deleting.id}`, { preserveScroll: true })}
            />
        </>
    )
}

function CreateDialog({ open, onOpenChange, base }: { open: boolean; onOpenChange: (open: boolean) => void; base: string }) {
    const form = useForm({ from: '', to: '', status: 301, match_type: 'exact', force: false, notes: '' })

    const submit = () =>
        form.post(base, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset()
                onOpenChange(false)
            },
        })

    const input = 'h-8 w-full rounded-[var(--radius-control)] border border-line bg-panel px-2.5 font-mono text-[12.5px] shadow-sm placeholder:text-fg-subtle focus:border-accent focus:ring-3 focus:ring-[var(--accent-ring)] focus:outline-none'

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title="New redirect"
            description="Point an old URL somewhere new."
            footer={
                <>
                    <Button onClick={() => onOpenChange(false)}>Cancel</Button>
                    <Button variant="primary" loading={form.processing} onClick={submit}>Add redirect</Button>
                </>
            }
        >
            <form
                className="space-y-4"
                onSubmit={(event) => {
                    event.preventDefault()
                    submit()
                }}
            >
                <div className="grid grid-cols-[1fr_auto_1fr] items-end gap-2">
                    <label className="block">
                        <span className="mb-1.5 block text-[12.5px] font-medium">From</span>
                        <input className={cn(input, form.errors.from && 'border-danger')} placeholder="/old-path" value={form.data.from} onChange={(event) => form.setData('from', event.target.value)} autoFocus />
                    </label>
                    <ArrowRight className="mb-2 size-4 text-fg-subtle" />
                    <label className="block">
                        <span className="mb-1.5 block text-[12.5px] font-medium">To</span>
                        <input className={cn(input, form.errors.to && 'border-danger')} placeholder={form.data.status === 410 ? 'Not needed for 410' : '/new-path'} value={form.data.to} disabled={form.data.status === 410} onChange={(event) => form.setData('to', event.target.value)} />
                    </label>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <span className="mb-1.5 block text-[12.5px] font-medium">Match</span>
                        <Segmented label="Match" value={form.data.match_type} onChange={(value) => form.setData('match_type', value)} options={[{ value: 'exact', label: 'Exact' }, { value: 'prefix', label: 'Prefix' }, { value: 'regex', label: 'Regex' }]} />
                    </div>
                    <div>
                        <span className="mb-1.5 block text-[12.5px] font-medium">Response</span>
                        <Segmented label="Response" value={String(form.data.status)} onChange={(value) => form.setData('status', Number(value))} options={[{ value: '301', label: '301' }, { value: '302', label: '302' }, { value: '410', label: '410 gone' }]} />
                    </div>
                </div>

                <p className="text-[12px] text-fg-subtle">
                    {form.data.match_type === 'exact' && 'Only this exact path.'}
                    {form.data.match_type === 'prefix' && 'This path and everything under it, keeping the rest of the URL.'}
                    {form.data.match_type === 'regex' && 'A regular expression; use $1 in the target for captured groups.'}
                    {' '}
                    {form.data.status === 301 ? 'Permanent: search engines move their ranking to the new URL.' : form.data.status === 302 ? 'Temporary: search engines keep the old URL.' : 'Tells search engines the page is gone for good.'}
                </p>

                {Object.keys(form.errors).length > 0 && (
                    <ul className="rounded-[var(--radius-control)] bg-danger-soft px-3 py-2 text-[12.5px] text-danger">
                        {Object.entries(form.errors).map(([key, message]) => <li key={key}>{message}</li>)}
                    </ul>
                )}
            </form>
        </Dialog>
    )
}

function NotFoundView({ misses, base }: { misses: Miss[]; base: string }) {
    const [target, setTarget] = useState<Record<number, string>>({})

    if (misses.length === 0) {
        return (
            <Card>
                <EmptyState icon={<Link2Off className="size-5" />} title="No 404s logged" description="Requests for pages that do not exist show up here, so a broken link can become a redirect in one click." />
            </Card>
        )
    }

    return (
        <Card className="overflow-hidden">
            <ul className="divide-y divide-line">
                {misses.map((miss) => (
                    <li key={miss.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                        <div className="min-w-0 flex-1 basis-56">
                            <code className="block truncate text-[12.5px] font-medium">{miss.path}</code>
                            <span className="text-[12px] text-fg-subtle">
                                <span className="tabular-nums">{miss.hits} {miss.hits === 1 ? 'hit' : 'hits'}</span> · last {timeAgo(miss.lastSeenAt)} · from {miss.lastReferrer ? <span className="break-all">{miss.lastReferrer}</span> : 'direct'}
                            </span>
                        </div>
                        <form
                            className="flex items-center gap-1.5"
                            onSubmit={(event) => {
                                event.preventDefault()
                                router.post(`${base}/not-found/${miss.id}`, { to: target[miss.id] }, { preserveScroll: true })
                            }}
                        >
                            <ArrowRight className="size-3.5 text-fg-subtle" />
                            <input
                                className="h-8 w-48 rounded-[var(--radius-control)] border border-line bg-panel px-2.5 font-mono text-[12.5px] shadow-sm placeholder:text-fg-subtle focus:border-accent focus:ring-3 focus:ring-[var(--accent-ring)] focus:outline-none"
                                placeholder="/redirect-here"
                                value={target[miss.id] ?? ''}
                                onChange={(event) => setTarget((current) => ({ ...current, [miss.id]: event.target.value }))}
                                aria-label={`Redirect ${miss.path} to`}
                            />
                            <Button type="submit" size="sm" variant="primary" icon={<Wand2 className="size-3.5" />} disabled={!(target[miss.id] ?? '').trim()}>Redirect</Button>
                            <Button size="sm" variant="ghost" onClick={() => router.delete(`${base}/not-found/${miss.id}`, { preserveScroll: true })}>Ignore</Button>
                        </form>
                    </li>
                ))}
            </ul>
        </Card>
    )
}

function ImportButton({ url }: { url: string }) {
    return (
        <label className="button-quiet cursor-pointer">
            <Upload className="size-3.5" />
            Import
            <input
                type="file"
                accept=".csv,text/csv"
                hidden
                onChange={(event) => {
                    const file = event.target.files?.[0]

                    if (file !== undefined) router.post(url, { file }, { preserveScroll: true, forceFormData: true })

                    // Cleared so choosing the same file twice fires again.
                    event.target.value = ''
                }}
            />
        </label>
    )
}
