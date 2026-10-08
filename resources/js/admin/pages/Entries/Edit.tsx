import { Head, Link, router, useForm, usePage } from '@inertiajs/react'
import { ArrowLeft, Check, ChevronDown, CircleAlert, CloudOff, ExternalLink, Loader2, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react'
import LintPanel from '@/components/LintPanel'
import RevisionDrawer, { type Revision } from '@/components/RevisionDrawer'
import SeoPanel from '@/components/SeoPanel'
import { Button, Confirm, Kbd, Menu, MenuContent, MenuItem, MenuSeparator, MenuTrigger, StatusBadge, Tooltip, cn } from '@/components/ui'
import FieldRenderer, { isShown } from '@/fields/FieldRenderer'
import type { FieldSchema } from '@/fields/types'
import { useAutosave } from '@/hooks/useAutosave'
import { useLivePreview, type LintIssue, type SeoPreview } from '@/hooks/useLivePreview'
import { useUnsavedGuard } from '@/hooks/useUnsavedGuard'
import { timeAgo } from '@/lib/format'
import type { SharedProps } from '@/types'

interface CollectionProps {
    handle: string
    label: string
    singular: string
    routePattern: string | null
    indexPath: string | null
    fields: FieldSchema[]
}

/**
 * Field values, keyed by field name.
 *
 * `any` for the same reason the field registry's is: the shape is decided at
 * runtime by the schema. Each field component declares its own concrete type,
 * which is where the checking that matters happens.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type EntryValues = Record<string, any>

interface Props {
    collection: CollectionProps
    entry: { id: number; status: string; url: string | null; updatedAt: string | null } | null
    values: EntryValues
    revisions: Revision[]
    draft: { id: number; savedAt: string; values: EntryValues; source?: 'autosave' | 'proposal'; by?: string | null } | null
    lint: LintIssue[]
    seoPreview: SeoPreview | null
    seoChecks: LintIssue[]
}

/** Fields the editor places itself rather than in the general flow. */
const PLACED = new Set(['title', 'status', 'published_at'])
const CONTENT_TAB = 'Content'
const SEO_TAB = 'SEO'

/**
 * The entry editor.
 *
 * Writing in the centre, settings in an inspector on the right, the way
 * Notion, Ghost and WordPress's block editor all arrived at. Where a field
 * goes comes from the schema: `->tab()` picks its tab, `->sidebar()` moves it
 * to the inspector, and short settings (selects, toggles, dates, relations)
 * go there by default. Nothing here is specific to a collection.
 */
export default function Edit({ collection, entry, values: initial, revisions, draft, lint, seoPreview, seoChecks }: Props) {
    const { cms } = usePage<SharedProps>().props
    const base = `${cms.prefix}/${collection.handle}`

    const form = useForm<EntryValues>(initial)
    const [dismissedDraft, setDismissedDraft] = useState(false)
    const [confirmDelete, setConfirmDelete] = useState(false)
    const formRef = useRef<HTMLFormElement>(null)

    useUnsavedGuard(form.isDirty)

    const autosave = useAutosave({
        url: entry === null ? null : `${base}/${entry.id}/autosave`,
        values: form.data,
        enabled: form.isDirty,
    })

    const preview = useLivePreview(`${base}/preview`, form.data, { lint, seo: seoPreview, seoChecks }, entry?.id ?? null)

    const byName = useMemo(() => new Map(collection.fields.map((field) => [field.name, field])), [collection.fields])
    const titleField = byName.get('title')
    const statusField = byName.get('status')
    const publishedAtField = byName.get('published_at')
    const seoField = collection.fields.find((field) => field.type === 'seo')

    const visible = (field: FieldSchema) => isShown(field, form.data)
    const flow = collection.fields.filter((field) => !PLACED.has(field.name) && field.type !== 'seo' && visible(field))
    const inspectorFields = flow.filter((field) => field.sidebar)
    const mainFields = flow.filter((field) => !field.sidebar)

    const tabs = [CONTENT_TAB, ...new Set(mainFields.map((field) => field.tab).filter((tab): tab is string => Boolean(tab) && tab !== CONTENT_TAB))]
    if (seoField) tabs.push(SEO_TAB)
    const [tab, setTab] = useState(CONTENT_TAB)
    const tabFields = mainFields.filter((field) => (field.tab ?? CONTENT_TAB) === tab)

    // Which tabs hold an error, so a failed save points at the right one.
    const errorTabs = new Set(
        Object.keys(form.errors).map((key) => {
            const field = byName.get(key.split('.')[0] ?? '')

            if (!field) return CONTENT_TAB
            if (field.type === 'seo') return SEO_TAB

            return field.sidebar ? '' : (field.tab ?? CONTENT_TAB)
        }),
    )

    const isPublished = form.data.status === 'published'
    const wasPublished = entry?.status === 'published'

    const save = (status?: string): void => {
        const options = { preserveScroll: true }

        form.transform((data) => (status === undefined ? data : { ...data, status }))

        if (status !== undefined) form.setData('status', status)

        if (entry === null) form.post(base, options)
        else form.put(`${base}/${entry.id}`, options)
    }

    const submit = (event: FormEvent): void => {
        event.preventDefault()
        save()
    }

    // ⌘S saves without changing status.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
                event.preventDefault()
                formRef.current?.requestSubmit()
            }
        }
        window.addEventListener('keydown', onKey)

        return () => window.removeEventListener('keydown', onKey)
    }, [])

    const setValue = (name: string, value: unknown) => form.setData((current: EntryValues) => ({ ...current, [name]: value }))

    const title = String(form.data.title ?? '')
    const singular = collection.singular.toLowerCase()

    return (
        <>
            <Head title={title || `New ${singular}`} />

            <form ref={formRef} onSubmit={submit} className="-mx-4 -my-6 md:-mx-8 md:-my-8">
                {/* Editor bar: sticks under the top bar. */}
                <div className="sticky top-14 z-20 flex h-12 items-center gap-2 border-b border-line bg-canvas/85 px-4 backdrop-blur-md md:px-8">
                    <Tooltip content={`Back to ${collection.label.toLowerCase()}`}>
                        <Link href={base} className="inline-flex size-7 items-center justify-center rounded-md text-fg-muted hover:bg-hover hover:text-fg" aria-label={`Back to ${collection.label}`}>
                            <ArrowLeft className="size-4" />
                        </Link>
                    </Tooltip>
                    <span className="min-w-0 truncate text-[13px] font-medium">{title || <span className="text-fg-subtle">Untitled</span>}</span>
                    {entry !== null && <StatusBadge status={entry.status} />}

                    <div className="ml-auto flex items-center gap-1.5">
                        <SaveState dirty={form.isDirty} processing={form.processing} autosave={autosave} updatedAt={entry?.updatedAt ?? null} />

                        {entry?.url != null && entry.status === 'published' && (
                            <Tooltip content="View on site">
                                <a href={entry.url} target="_blank" rel="noreferrer" className="inline-flex size-8 items-center justify-center rounded-[var(--radius-control)] text-fg-muted hover:bg-hover hover:text-fg" aria-label="View on site">
                                    <ExternalLink className="size-4" />
                                </a>
                            </Tooltip>
                        )}

                        {entry !== null && <RevisionDrawer revisions={revisions} restoreUrl={(revision) => `${base}/${entry.id}/revisions/${revision.id}/restore`} />}

                        {statusField ? (
                            <div className="flex items-center">
                                {wasPublished ? (
                                    <Button type="button" variant="primary" loading={form.processing} onClick={() => save()} className="!rounded-r-none">
                                        Update
                                    </Button>
                                ) : (
                                    <>
                                        <span className="hidden sm:contents">
                                            <Button type="button" variant="ghost" onClick={() => save('draft')} disabled={form.processing} className="mr-1.5">
                                                Save draft
                                            </Button>
                                        </span>
                                        <Button type="button" variant="primary" loading={form.processing} onClick={() => save('published')} className="!rounded-r-none">
                                            Publish
                                        </Button>
                                    </>
                                )}
                                <Menu>
                                    <MenuTrigger asChild>
                                        <Button type="button" variant="primary" size="icon" className="!w-7 !rounded-l-none border-l border-white/20" aria-label="More save options">
                                            <ChevronDown className="size-3.5" />
                                        </Button>
                                    </MenuTrigger>
                                    <MenuContent className="w-56">
                                        <MenuItem shortcut="⌘S" onSelect={() => save()}>Save, keep status</MenuItem>
                                        {wasPublished ? (
                                            <MenuItem onSelect={() => save('draft')}>Unpublish, revert to draft</MenuItem>
                                        ) : (
                                            <MenuItem onSelect={() => save('draft')}>Save as draft</MenuItem>
                                        )}
                                        {entry !== null && (
                                            <>
                                                <MenuSeparator />
                                                <MenuItem danger icon={<Trash2 />} onSelect={() => setConfirmDelete(true)}>Delete {singular}</MenuItem>
                                            </>
                                        )}
                                    </MenuContent>
                                </Menu>
                            </div>
                        ) : (
                            <Button type="submit" variant="primary" loading={form.processing}>Save</Button>
                        )}
                    </div>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_340px]">
                    {/* Writing */}
                    <div className="min-w-0 px-4 py-8 md:px-10 lg:border-r lg:border-line">
                        <div className="mx-auto max-w-[760px]">
                            {Object.keys(form.errors).length > 0 && (
                                <div className="mb-6 flex gap-3 rounded-[var(--radius-card)] border border-danger/30 bg-danger-soft px-4 py-3 text-[13px] animate-in" role="alert">
                                    <CircleAlert className="mt-0.5 size-4 shrink-0 text-danger" />
                                    <div>
                                        <p className="font-medium text-danger">This did not save.</p>
                                        <ul className="mt-1 space-y-0.5">
                                            {Object.entries(form.errors).map(([key, message]) => (
                                                <li key={key}>
                                                    <button
                                                        type="button"
                                                        className="font-medium text-fg underline decoration-line-strong underline-offset-2 hover:decoration-fg"
                                                        onClick={() => {
                                                            const field = byName.get(key.split('.')[0] ?? '')

                                                            if (field && !field.sidebar) setTab(field.type === 'seo' ? SEO_TAB : (field.tab ?? CONTENT_TAB))
                                                            setTimeout(() => document.getElementById(`field-${key.split('.')[0]}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 50)
                                                        }}
                                                    >
                                                        {labelFor(collection.fields, key)}
                                                    </button>
                                                    <span className="text-fg-muted">: {String(message)}</span>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                </div>
                            )}

                            {/*
                                An autosave newer than the entry means a browser closed with
                                unsaved work in it. Offered rather than applied.
                            */}
                            {draft !== null && !dismissedDraft && (
                                <div className={cn('mb-6 flex flex-wrap items-center justify-between gap-3 rounded-[var(--radius-card)] border px-4 py-3 text-[13px] animate-in', draft.source === 'proposal' ? 'border-accent/30 bg-accent-soft' : 'border-line bg-info-soft')}>
                                    <span>
                                        {draft.source === 'proposal' ? (
                                            <>
                                                <span className="font-semibold">Changes proposed{draft.by ? ` by ${draft.by}` : ''} from the CLI</span>{' '}
                                                <span className="text-fg-muted">on {new Date(draft.savedAt).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' })}. Apply them to review in the form, then save; nothing changes on the site until you do.</span>
                                            </>
                                        ) : (
                                            <>Unsaved work from {new Date(draft.savedAt).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' })} was recovered.</>
                                        )}
                                    </span>
                                    <div className="flex gap-1.5">
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => {
                                                setDismissedDraft(true)
                                                if (entry !== null) router.delete(`${base}/${entry.id}/draft`, { preserveScroll: true, preserveState: true })
                                            }}
                                        >
                                            Discard
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant={draft.source === 'proposal' ? 'primary' : 'secondary'}
                                            onClick={() => {
                                                // A snapshot stores jsonb fields nested under `data`; the form holds them flat.
                                                form.setData({
                                                    ...form.data,
                                                    ...((draft.values.data ?? {}) as EntryValues),
                                                    title: draft.values.title,
                                                    slug: draft.values.slug,
                                                    status: draft.values.status,
                                                    seo: draft.values.seo,
                                                    // Tells the save that this decides the proposal.
                                                    ...(draft.source === 'proposal' ? { _proposal_applied: true } : {}),
                                                })
                                                setDismissedDraft(true)
                                            }}
                                        >
                                            {draft.source === 'proposal' ? 'Apply changes' : 'Restore it'}
                                        </Button>
                                    </div>
                                </div>
                            )}

                            {titleField && (
                                <div id="field-title" className="mb-6">
                                    <TitleInput
                                        value={title}
                                        maxLength={titleField.maxLength ?? undefined}
                                        placeholder={`${collection.singular} title`}
                                        onChange={(value) => setValue('title', value)}
                                    />
                                    {form.errors.title && <p className="field-error">{form.errors.title}</p>}
                                </div>
                            )}

                            {tabs.length > 1 && (
                                <div role="tablist" className="mb-6 flex gap-5 border-b border-line">
                                    {tabs.map((name) => (
                                        <button
                                            key={name}
                                            type="button"
                                            role="tab"
                                            aria-selected={tab === name}
                                            onClick={() => setTab(name)}
                                            className={cn(
                                                '-mb-px flex items-center gap-1.5 border-b-2 pb-2.5 text-[13px] font-medium transition-colors',
                                                tab === name ? 'border-accent text-fg' : 'border-transparent text-fg-muted hover:text-fg',
                                            )}
                                        >
                                            {name}
                                            {errorTabs.has(name) && <span className="size-1.5 rounded-full bg-danger" aria-label="Has errors" />}
                                        </button>
                                    ))}
                                </div>
                            )}

                            <div className="flex flex-col gap-6">
                                {tab === SEO_TAB && seoField ? (
                                    <FieldRenderer field={seoField} value={form.data[seoField.name]} values={form.data} error={form.errors[seoField.name]} onChange={setValue} />
                                ) : (
                                    tabFields.map((field) => (
                                        <FieldRenderer key={field.name} field={field} value={form.data[field.name]} values={form.data} error={form.errors[field.name]} onChange={setValue} />
                                    ))
                                )}
                                {tab !== SEO_TAB && tabFields.length === 0 && <p className="py-10 text-center text-[13px] text-fg-subtle">Nothing on this tab.</p>}
                            </div>
                        </div>
                    </div>

                    {/* Inspector */}
                    <aside className="space-y-3 bg-panel/40 px-4 py-6 md:px-6 lg:sticky lg:top-[6.5rem] lg:h-[calc(100dvh-6.5rem)] lg:overflow-y-auto">
                        {(statusField || publishedAtField) && (
                            <InspectorSection title="Publishing">
                                <div className="space-y-4">
                                    {statusField && (
                                        <div className="flex items-center justify-between text-[13px]">
                                            <span className="text-fg-muted">Status</span>
                                            <StatusBadge status={isPublished ? 'published' : String(form.data.status || 'draft')} />
                                        </div>
                                    )}
                                    {publishedAtField && (
                                        <FieldRenderer field={{ ...publishedAtField, label: 'Publish date' }} value={form.data.published_at} values={form.data} error={form.errors.published_at} onChange={setValue} />
                                    )}
                                </div>
                            </InspectorSection>
                        )}

                        {inspectorFields.length > 0 && (
                            <InspectorSection title="Details" hasError={errorTabs.has('')}>
                                <div className="flex flex-col gap-4">
                                    {inspectorFields.map((field) => (
                                        <FieldRenderer key={field.name} field={field} value={form.data[field.name]} values={form.data} error={form.errors[field.name]} onChange={setValue} />
                                    ))}
                                </div>
                            </InspectorSection>
                        )}

                        <LintPanel issues={preview.lint} checking={preview.checking} />
                        <SeoPanel seo={preview.seo} checks={preview.seoChecks} publishing={isPublished} />

                        <p className="flex items-center justify-center gap-1.5 pt-2 text-[11.5px] text-fg-subtle">
                            <Kbd>⌘</Kbd><Kbd>S</Kbd> to save
                        </p>
                    </aside>
                </div>
            </form>

            {entry !== null && (
                <Confirm
                    open={confirmDelete}
                    onOpenChange={setConfirmDelete}
                    title={`Delete this ${singular}?`}
                    description="The page stops being served immediately. It is soft deleted, so the content can be recovered."
                    confirmLabel="Delete"
                    danger
                    onConfirm={() => form.delete(`${base}/${entry.id}`)}
                />
            )}
        </>
    )
}

/** The title, as a heading you type into. Grows with its text at any width. */
function TitleInput({ value, maxLength, placeholder, onChange }: { value: string; maxLength?: number; placeholder: string; onChange: (value: string) => void }) {
    const ref = useRef<HTMLTextAreaElement>(null)

    useEffect(() => {
        const el = ref.current

        if (!el) return

        const fit = () => {
            el.style.height = 'auto'
            el.style.height = `${el.scrollHeight}px`
        }
        fit()

        const observer = new ResizeObserver(fit)
        observer.observe(el)

        return () => observer.disconnect()
    }, [value])

    return (
        <textarea
            ref={ref}
            aria-label="Title"
            rows={1}
            value={value}
            maxLength={maxLength}
            placeholder={placeholder}
            onChange={(event) => onChange(event.target.value.replace(/\n/g, ''))}
            className="w-full resize-none overflow-hidden bg-transparent text-[26px] leading-[1.15] font-semibold tracking-[-0.025em] text-fg outline-none placeholder:text-fg-subtle/70 focus-visible:outline-none md:text-[32px]"
        />
    )
}

function InspectorSection({ title, hasError = false, children }: { title: string; hasError?: boolean; children: ReactNode }) {
    return (
        <section className="rounded-[var(--radius-card)] border border-line bg-panel p-4 shadow-sm">
            <h2 className="mb-3 flex items-center gap-1.5 text-[12.5px] font-semibold">
                {title}
                {hasError && <span className="size-1.5 rounded-full bg-danger" aria-label="Has errors" />}
            </h2>
            {children}
        </section>
    )
}

/**
 * The label an editor would recognise, for an error keyed by field name.
 * Errors on a nested key arrive as `seo.title`, so the lookup is on the first
 * segment.
 */
function labelFor(fields: FieldSchema[], key: string): string {
    const root = key.split('.')[0]

    return fields.find((field) => field.name === root)?.label ?? key
}

function SaveState({ dirty, processing, autosave, updatedAt }: { dirty: boolean; processing: boolean; autosave: { savedAt: string | null; saving: boolean; failed: boolean }; updatedAt: string | null }) {
    const [, tick] = useState(0)

    // Keep "saved 2m ago" honest.
    useEffect(() => {
        const timer = setInterval(() => tick((n) => n + 1), 30_000)

        return () => clearInterval(timer)
    }, [])

    let icon: ReactNode = <Check className="size-3.5 text-success" />
    let text = updatedAt ? `Saved ${timeAgo(updatedAt)}` : 'Not saved yet'

    if (processing) {
        icon = <Loader2 className="size-3.5 animate-spin" />
        text = 'Saving'
    } else if (autosave.failed) {
        icon = <CloudOff className="size-3.5 text-warning" />
        text = 'Autosave failed'
    } else if (autosave.saving) {
        icon = <Loader2 className="size-3.5 animate-spin" />
        text = 'Drafting'
    } else if (dirty) {
        icon = <span className="size-1.5 rounded-full bg-warning" />
        text = autosave.savedAt ? `Unsaved, drafted ${timeAgo(autosave.savedAt)}` : 'Unsaved changes'
    }

    return (
        <span className="mr-1 hidden items-center gap-1.5 text-[12px] text-fg-subtle md:inline-flex" aria-live="polite">
            {icon}
            {text}
        </span>
    )
}
