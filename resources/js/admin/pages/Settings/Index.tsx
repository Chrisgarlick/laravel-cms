import { Head, router, useForm, usePage } from '@inertiajs/react'
import { Database, FileCode2, Globe, IdCard, RotateCcw, Search } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { Button, Card, Confirm, PageHeader, cn } from '@/components/ui'
import { useUnsavedGuard } from '@/hooks/useUnsavedGuard'
import { timeAgo } from '@/lib/format'
import type { SharedProps } from '@/types'

interface Props {
    values: Record<string, string | null>
    defaults: Record<string, string | null>
    overridden: string[]
    cache: { pages: number; bytes: number; oldest: string | null }
}

interface Group {
    id: string
    title: string
    icon: ReactNode
    note?: string
    keys: Array<[string, string, string?]>
}

const GROUPS: Group[] = [
    {
        id: 'site',
        title: 'Site',
        icon: <Globe />,
        keys: [
            ['site.name', 'Name', 'Shown in titles, emails and the admin.'],
            ['site.domain', 'Domain', 'The canonical address, with https://.'],
            ['site.contact_email', 'Contact email', 'Where lead notifications go.'],
        ],
    },
    {
        id: 'search',
        title: 'Search and social',
        icon: <Search />,
        note: 'Defaults for every page. An entry can override its own title, description and image.',
        keys: [
            ['seo.title_template', 'Title template', 'Use {title} and {site}.'],
            ['seo.description', 'Default description'],
            ['seo.image', 'Default social image', 'A URL or media path, 1200 × 630.'],
            ['seo.image_alt', 'Social image alt text'],
            ['seo.twitter', 'X / Twitter handle'],
            ['seo.area_served', 'Area served'],
            ['seo.search_url', 'Search URL'],
        ],
    },
    {
        id: 'identity',
        title: 'Identity',
        icon: <IdCard />,
        note: 'Emitted as Person and Organization in the structured data on every page.',
        keys: [
            ['seo.person.name', 'Person name'],
            ['seo.person.job_title', 'Job title'],
            ['seo.organization.name', 'Organisation name'],
            ['seo.organization.logo', 'Organisation logo'],
        ],
    },
]

/**
 * Site settings, and the two cache controls worth exposing.
 *
 * Every field is an override with a config default behind it: the default is
 * the placeholder, and clearing a field reverts rather than blanks. Settings
 * that change how the application is wired are deliberately absent; a
 * settings screen that can repoint the database can take the site down.
 */
export default function Index({ values, defaults, overridden, cache }: Props) {
    const { cms } = usePage<SharedProps>().props
    const base = `${cms.prefix}/settings`
    const [confirmFlush, setConfirmFlush] = useState(false)

    const form = useForm<Record<string, string>>(Object.fromEntries(Object.entries(values).map(([key, value]) => [key, value ?? ''])))

    useUnsavedGuard(form.isDirty)

    const save = () => form.put(base, { preserveScroll: true, onSuccess: () => form.setDefaults() })

    return (
        <>
            <Head title="Settings" />

            <PageHeader title="Settings" description="Site-wide defaults. Anything left empty uses the value from config." />

            <div className="grid gap-8 lg:grid-cols-[200px_minmax(0,1fr)]">
                <nav className="hidden lg:block" aria-label="Settings sections">
                    <ul className="sticky top-20 space-y-px">
                        {[...GROUPS, { id: 'cache', title: 'Cache and files', icon: <Database /> }].map((group) => (
                            <li key={group.id}>
                                <a href={`#${group.id}`} className="flex h-8 items-center gap-2.5 rounded-[var(--radius-control)] px-2.5 text-[13px] text-fg-muted hover:bg-hover hover:text-fg [&>svg]:size-4 [&>svg]:text-fg-subtle">
                                    {group.icon}
                                    {group.title}
                                </a>
                            </li>
                        ))}
                    </ul>
                </nav>

                <div className="max-w-3xl space-y-6 pb-24">
                    {GROUPS.map((group) => (
                        <Card key={group.id} className="scroll-mt-20 overflow-hidden">
                            <section id={group.id}>
                                <header className="border-b border-line px-5 py-4">
                                    <h2 className="text-[14px] font-semibold">{group.title}</h2>
                                    {group.note && <p className="mt-0.5 text-[12.5px] text-fg-muted">{group.note}</p>}
                                </header>
                                <div className="divide-y divide-line">
                                    {group.keys.map(([key, label, hint]) => {
                                        const changed = overridden.includes(key)

                                        return (
                                            <div key={key} className="grid gap-2 px-5 py-4 sm:grid-cols-[200px_minmax(0,1fr)] sm:gap-6">
                                                <div>
                                                    <label htmlFor={`setting-${key}`} className="text-[13px] font-medium">{label}</label>
                                                    {hint && <p className="mt-0.5 text-[12px] text-fg-subtle">{hint}</p>}
                                                </div>
                                                <div>
                                                    <input
                                                        id={`setting-${key}`}
                                                        type="text"
                                                        className="field-input"
                                                        value={form.data[key] ?? ''}
                                                        placeholder={defaults[key] ?? 'Not set'}
                                                        onChange={(event) => form.setData(key, event.target.value)}
                                                    />
                                                    {form.errors[key] !== undefined && <p className="field-error">{form.errors[key]}</p>}
                                                    {changed && (
                                                        <button type="button" onClick={() => form.setData(key, '')} className="mt-1.5 inline-flex items-center gap-1 text-[12px] text-fg-subtle hover:text-fg">
                                                            <RotateCcw className="size-3" />
                                                            Changed from the default. Reset{defaults[key] ? ` to "${truncate(defaults[key] ?? '', 40)}"` : ''}
                                                        </button>
                                                    )}
                                                </div>
                                            </div>
                                        )
                                    })}
                                </div>
                            </section>
                        </Card>
                    ))}

                    <Card className="scroll-mt-20 overflow-hidden">
                        <section id="cache">
                            <header className="border-b border-line px-5 py-4">
                                <h2 className="text-[14px] font-semibold">Cache and files</h2>
                                <p className="mt-0.5 text-[12.5px] text-fg-muted">Both rebuild themselves when content changes. These are for after a template or settings change, which the cache cannot see.</p>
                            </header>
                            <div className="divide-y divide-line">
                                <div className="flex flex-wrap items-center justify-between gap-4 px-5 py-4">
                                    <div className="flex items-start gap-3">
                                        <Database className="mt-0.5 size-4 text-fg-subtle" />
                                        <div>
                                            <div className="text-[13px] font-medium">Page cache</div>
                                            <div className="text-[12.5px] text-fg-muted tabular-nums">
                                                {cache.pages} pages, {formatKb(cache.bytes)}{cache.oldest ? `, oldest ${timeAgo(cache.oldest)}` : ''}
                                            </div>
                                        </div>
                                    </div>
                                    <Button size="sm" onClick={() => setConfirmFlush(true)}>Empty the cache</Button>
                                </div>
                                <div className="flex flex-wrap items-center justify-between gap-4 px-5 py-4">
                                    <div className="flex items-start gap-3">
                                        <FileCode2 className="mt-0.5 size-4 text-fg-subtle" />
                                        <div>
                                            <div className="text-[13px] font-medium">Machine-readable files</div>
                                            <div className="text-[12.5px] text-fg-muted">sitemap.xml, robots.txt, llms.txt and the feed</div>
                                        </div>
                                    </div>
                                    <Button size="sm" onClick={() => router.post(`${base}/rebuild-files`, {}, { preserveScroll: true })}>Rebuild now</Button>
                                </div>
                            </div>
                        </section>
                    </Card>
                </div>
            </div>

            {/* Save bar: only while something has changed. */}
            <div className={cn('fixed bottom-5 left-1/2 z-40 flex -translate-x-1/2 items-center gap-3 rounded-[var(--radius-card)] border border-line bg-elevated py-2 pr-2 pl-4 shadow-lg transition-[opacity,transform] duration-200', form.isDirty ? 'opacity-100' : 'pointer-events-none translate-y-3 opacity-0')}>
                <span className="text-[13px] font-medium">Unsaved changes</span>
                <Button size="sm" variant="ghost" onClick={() => form.reset()}>Discard</Button>
                <Button size="sm" variant="primary" loading={form.processing} onClick={save}>Save changes</Button>
            </div>

            <Confirm open={confirmFlush} onOpenChange={setConfirmFlush} title="Empty the page cache?" description="Every page is rebuilt on its next visit, so the first visitor to each waits a little longer. Nothing is lost." confirmLabel="Empty it" onConfirm={() => router.post(`${base}/flush-cache`, {}, { preserveScroll: true })} />
        </>
    )
}

function formatKb(bytes: number): string {
    return bytes > 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.round(bytes / 1024)} KB`
}

function truncate(value: string, length: number): string {
    return value.length > length ? `${value.slice(0, length)}…` : value
}
