import { Head, router, usePage } from '@inertiajs/react'
import { ArrowLeft, CircleAlert, Copy, Download, ExternalLink, Inbox, Mail, ShieldAlert, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import Paginator from '@/components/Paginator'
import { Badge, Button, Card, Confirm, EmptyState, PageHeader, Segmented, Tooltip, cn, toast } from '@/components/ui'
import { timeAgo } from '@/lib/format'
import type { SharedProps } from '@/types'

interface Submission {
    id: number
    form: string
    context: string | null
    data: Record<string, unknown>
    referrer: string | null
    rejectedFor: string | null
    notifiedAt: string | null
    submittedAt: string | null
}

interface Props {
    filters: { form: string; rejected: boolean }
    forms: Array<{ slug: string; name: string; fields: string[]; accepted: number; rejected: number }>
    submissions: { data: Submission[]; total: number; currentPage: number; lastPage: number }
}

/**
 * Leads, as an inbox: the list on the left, the lead on the right.
 *
 * The database row is the record and the email is only a notification, so a
 * lead whose email failed is flagged here rather than lost. Blocked
 * submissions are kept and one click away: a spam rule that is too strict
 * looks exactly like a quiet month.
 */
export default function Index({ filters, forms, submissions }: Props) {
    const { cms } = usePage<SharedProps>().props
    const base = `${cms.prefix}/submissions`
    const [selectedId, setSelectedId] = useState<number | null>(() => {
        const fromHash = Number(window.location.hash.replace('#lead-', ''))

        return Number.isFinite(fromHash) && fromHash > 0 ? fromHash : null
    })
    const [confirmDelete, setConfirmDelete] = useState(false)

    const selected = submissions.data.find((s) => s.id === selectedId) ?? null
    const formNames = useMemo(() => Object.fromEntries(forms.map((f) => [f.slug, f.name])), [forms])

    // Open the newest lead on wide screens, so the right pane is never empty.
    useEffect(() => {
        if (selectedId === null && submissions.data[0] && window.matchMedia('(min-width: 1024px)').matches) {
            setSelectedId(submissions.data[0].id)
        }
    }, [submissions.data, selectedId])

    useEffect(() => {
        if (selectedId !== null) history.replaceState(null, '', `#lead-${selectedId}`)
    }, [selectedId])

    // j / k to move through the list, like every mail client.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) || event.metaKey || event.ctrlKey) return

            const index = submissions.data.findIndex((s) => s.id === selectedId)
            if (event.key === 'j' && index < submissions.data.length - 1) setSelectedId(submissions.data[index + 1]!.id)
            if (event.key === 'k' && index > 0) setSelectedId(submissions.data[index - 1]!.id)
        }
        window.addEventListener('keydown', onKey)

        return () => window.removeEventListener('keydown', onKey)
    }, [submissions.data, selectedId])

    const go = (changes: Record<string, string | undefined>): void => {
        setSelectedId(null)
        router.get(base, { form: filters.form || undefined, rejected: filters.rejected ? '1' : undefined, ...changes }, { preserveScroll: true, preserveState: true })
    }

    const exportUrl = `${base}/export?${new URLSearchParams({ ...(filters.form ? { form: filters.form } : {}), ...(filters.rejected ? { rejected: '1' } : {}) }).toString()}`
    const accepted = forms.reduce((sum, f) => sum + f.accepted, 0)
    const rejected = forms.reduce((sum, f) => sum + f.rejected, 0)

    return (
        <>
            <Head title="Leads" />

            <PageHeader
                title="Leads"
                description="Everything submitted through the site's forms. The email is a notification; this is the record."
                actions={<a href={exportUrl} className="button-quiet"><Download className="size-3.5" />Export CSV</a>}
            />

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <Segmented<'inbox' | 'blocked'>
                    label="Show"
                    value={filters.rejected ? 'blocked' : 'inbox'}
                    onChange={(value) => go({ rejected: value === 'blocked' ? '1' : undefined, page: undefined })}
                    options={[
                        { value: 'inbox', label: 'Inbox', count: accepted },
                        { value: 'blocked', label: 'Blocked as spam', count: rejected },
                    ]}
                />
                <select
                    className="field-input !h-8 !min-h-0 !w-auto !py-0 text-[13px]"
                    value={filters.form}
                    onChange={(event) => go({ form: event.target.value || undefined, page: undefined })}
                    aria-label="Form"
                >
                    <option value="">All forms</option>
                    {forms.map((form) => (
                        <option key={form.slug} value={form.slug}>{form.name} ({filters.rejected ? form.rejected : form.accepted})</option>
                    ))}
                </select>
                <span className="ml-auto hidden items-center gap-1 text-[12px] text-fg-subtle lg:flex">
                    <kbd className="rounded border border-line px-1 font-sans text-[10.5px]">j</kbd>
                    <kbd className="rounded border border-line px-1 font-sans text-[10.5px]">k</kbd>
                    to move
                </span>
            </div>

            {filters.rejected && (
                <div className="mb-4 flex items-start gap-2.5 rounded-[var(--radius-card)] border border-warning/25 bg-warning-soft px-4 py-3 text-[13px]">
                    <ShieldAlert className="mt-0.5 size-4 shrink-0 text-warning" />
                    <span>These were blocked by the spam guard. Skim them now and then: a rule that is too strict looks exactly like a quiet month.</span>
                </div>
            )}

            {submissions.data.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={<Inbox className="size-5" />}
                        title={filters.rejected ? 'Nothing blocked' : 'No leads yet'}
                        description={filters.rejected ? 'The spam guard has not rejected anything with these filters.' : 'Enquiries, audit requests and resource downloads arrive here.'}
                    />
                </Card>
            ) : (
                <Card className="grid min-h-[560px] overflow-hidden lg:grid-cols-[minmax(300px,380px)_minmax(0,1fr)]">
                    {/* List */}
                    <ul className={cn('divide-y divide-line overflow-y-auto lg:max-h-[calc(100dvh-16rem)] lg:border-r lg:border-line', selected && 'hidden lg:block')}>
                        {submissions.data.map((submission) => {
                            const who = identity(submission)
                            const active = submission.id === selectedId
                            const unsent = submission.rejectedFor === null && submission.notifiedAt === null

                            return (
                                <li key={submission.id}>
                                    <button
                                        type="button"
                                        onClick={() => setSelectedId(submission.id)}
                                        className={cn('relative flex w-full gap-3 px-4 py-3 text-left transition-colors', active ? 'bg-selected' : 'hover:bg-hover/60')}
                                    >
                                        {active && <span className="absolute inset-y-0 left-0 w-0.5 bg-accent" />}
                                        <Avatar name={who.name} />
                                        <span className="min-w-0 flex-1">
                                            <span className="flex items-baseline justify-between gap-2">
                                                <span className="truncate text-[13.5px] font-semibold">{who.name}</span>
                                                <span className="shrink-0 text-[11.5px] text-fg-subtle">{timeAgo(submission.submittedAt)}</span>
                                            </span>
                                            <span className="mt-0.5 flex items-center gap-1.5 text-[12px] text-fg-muted">
                                                {unsent && <span className="size-1.5 shrink-0 rounded-full bg-danger" aria-label="Not emailed" />}
                                                <span className="truncate">{formNames[submission.form] ?? submission.form}{submission.context ? ` · ${submission.context}` : ''}</span>
                                            </span>
                                            {who.snippet && <span className="mt-1 line-clamp-2 text-[12.5px] leading-snug text-fg-subtle">{who.snippet}</span>}
                                        </span>
                                    </button>
                                </li>
                            )
                        })}
                    </ul>

                    {/* Lead */}
                    <div className={cn('min-w-0', !selected && 'hidden lg:block')}>
                        {selected ? (
                            <LeadDetail key={selected.id} submission={selected} formName={formNames[selected.form] ?? selected.form} onBack={() => setSelectedId(null)} onDelete={() => setConfirmDelete(true)} />
                        ) : (
                            <div className="flex h-full items-center justify-center p-10 text-[13px] text-fg-subtle">Choose a lead to read it.</div>
                        )}
                    </div>
                </Card>
            )}

            <Paginator currentPage={submissions.currentPage} lastPage={submissions.lastPage} onGo={(page) => go({ page: page === 1 ? undefined : String(page) })} />

            {selected && (
                <Confirm
                    open={confirmDelete}
                    onOpenChange={setConfirmDelete}
                    title="Delete this lead?"
                    description="It is removed for good, including from exports. For a GDPR erasure request across every table, use php artisan gdpr:erase instead."
                    confirmLabel="Delete"
                    danger
                    onConfirm={() => router.delete(`${base}/${selected.id}`, { preserveScroll: true, onSuccess: () => setSelectedId(null) })}
                />
            )}
        </>
    )
}

function LeadDetail({ submission, formName, onBack, onDelete }: { submission: Submission; formName: string; onBack: () => void; onDelete: () => void }) {
    const who = identity(submission)
    const email = who.email
    const unsent = submission.rejectedFor === null && submission.notifiedAt === null
    const fields = Object.entries(submission.data).filter(([key]) => !['name', 'email'].includes(key) || !email)

    const copy = (text: string) => void navigator.clipboard.writeText(text).then(() => toast('Copied'), () => toast('Could not copy', { tone: 'error' }))

    return (
        <div className="flex h-full flex-col animate-in">
            <div className="flex items-start gap-3 border-b border-line px-5 py-4">
                <Button size="icon-sm" variant="ghost" className="lg:hidden" onClick={onBack} aria-label="Back to the list"><ArrowLeft className="size-4" /></Button>
                <Avatar name={who.name} large />
                <div className="min-w-0 flex-1">
                    <h2 className="truncate text-[16px] font-semibold">{who.name}</h2>
                    {email && (
                        <button type="button" onClick={() => copy(email)} className="group inline-flex items-center gap-1.5 text-[13px] text-fg-muted hover:text-fg">
                            {email}
                            <Copy className="size-3 opacity-0 group-hover:opacity-100" />
                        </button>
                    )}
                </div>
                <div className="flex shrink-0 items-center gap-1.5">
                    {email && submission.rejectedFor === null && (
                        <a href={`mailto:${email}?subject=${encodeURIComponent(`Re: your ${formName.toLowerCase()}`)}`} className="button !h-8"><Mail className="size-3.5" />Reply</a>
                    )}
                    <Tooltip content="Delete">
                        <Button size="icon" variant="ghost" onClick={onDelete} aria-label="Delete lead"><Trash2 className="size-4" /></Button>
                    </Tooltip>
                </div>
            </div>

            <div className="flex-1 space-y-5 overflow-y-auto px-5 py-5">
                {unsent && (
                    <div className="flex items-start gap-2.5 rounded-[var(--radius-card)] border border-danger/25 bg-danger-soft px-3.5 py-2.5 text-[13px]">
                        <CircleAlert className="mt-0.5 size-4 shrink-0 text-danger" />
                        <span><span className="font-semibold">The notification email was not sent.</span> <span className="text-fg-muted">This lead is only here. Reply directly.</span></span>
                    </div>
                )}
                {submission.rejectedFor !== null && (
                    <div className="flex items-start gap-2.5 rounded-[var(--radius-card)] border border-warning/25 bg-warning-soft px-3.5 py-2.5 text-[13px]">
                        <ShieldAlert className="mt-0.5 size-4 shrink-0 text-warning" />
                        <span>Blocked by the spam guard: <span className="font-semibold">{submission.rejectedFor}</span>. If it is real, reply by hand.</span>
                    </div>
                )}

                <div className="flex flex-wrap gap-1.5">
                    <Badge tone="accent">{formName}</Badge>
                    {submission.context && <Badge>{submission.context}</Badge>}
                    {submission.notifiedAt && <Badge tone="success">Emailed {timeAgo(submission.notifiedAt)}</Badge>}
                </div>

                <dl className="divide-y divide-line rounded-[var(--radius-card)] border border-line">
                    {fields.map(([key, value]) => {
                        const text = render(value)
                        const long = text.length > 80 || text.includes('\n')

                        return (
                            <div key={key} className={cn('gap-4 px-4 py-3', long ? 'block' : 'grid grid-cols-[140px_minmax(0,1fr)]')}>
                                <dt className="text-[12px] font-medium text-fg-subtle">{humanise(key)}</dt>
                                <dd className={cn('text-[13.5px] break-words', long && 'mt-1.5 leading-relaxed whitespace-pre-wrap')}>{text || <span className="text-fg-subtle">empty</span>}</dd>
                            </div>
                        )
                    })}
                </dl>

                <dl className="grid grid-cols-[140px_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-[12.5px]">
                    <dt className="text-fg-subtle">Received</dt>
                    <dd>{submission.submittedAt ? new Date(submission.submittedAt).toLocaleString('en-GB', { dateStyle: 'full', timeStyle: 'short' }) : 'Unknown'}</dd>
                    {submission.referrer && (
                        <>
                            <dt className="text-fg-subtle">Came from</dt>
                            <dd className="truncate"><a href={submission.referrer} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-fg-muted hover:text-accent">{submission.referrer}<ExternalLink className="size-3" /></a></dd>
                        </>
                    )}
                </dl>
            </div>
        </div>
    )
}

/** Who sent it, best guess from the usual field names. */
function identity(submission: Submission): { name: string; email: string | null; snippet: string } {
    const data = submission.data
    const pick = (...keys: string[]) => keys.map((key) => data[key]).find((value) => typeof value === 'string' && value.trim() !== '') as string | undefined

    const email = pick('email', 'email_address', 'work_email') ?? null
    const name = pick('name', 'full_name', 'first_name', 'company') ?? email ?? 'Anonymous'
    const snippet = pick('message', 'enquiry', 'details', 'notes', 'project', 'url', 'website') ?? ''

    return { name, email, snippet }
}

function Avatar({ name, large = false }: { name: string; large?: boolean }) {
    const initials = name.split(/[\s@.]+/).filter(Boolean).slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join('')
    const hue = [...name].reduce((sum, char) => sum + char.charCodeAt(0), 0) % 360

    return (
        <span className={cn('flex shrink-0 items-center justify-center rounded-full font-semibold text-white', large ? 'size-10 text-[14px]' : 'size-8 text-[11.5px]')} style={{ background: `oklch(0.62 0.12 ${hue})` }} aria-hidden>
            {initials || '?'}
        </span>
    )
}

function humanise(key: string): string {
    const words = key.replace(/[_-]+/g, ' ').trim()

    return words.charAt(0).toUpperCase() + words.slice(1)
}

function render(value: unknown): string {
    if (Array.isArray(value)) return value.join(', ')
    if (typeof value === 'boolean') return value ? 'Yes' : 'No'
    if (value !== null && typeof value === 'object') return JSON.stringify(value)

    return String(value ?? '')
}
