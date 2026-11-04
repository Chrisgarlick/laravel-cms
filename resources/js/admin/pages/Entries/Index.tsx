import { Head, Link, router, usePage } from '@inertiajs/react'
import { ExternalLink, Plus, Search, X } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import Paginator from '@/components/Paginator'
import { Button, Card, Checkbox, Confirm, EmptyState, Kbd, PageHeader, Segmented, StatusBadge, Tooltip } from '@/components/ui'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { collectionIcon } from '@/layouts/nav'
import { formatDate, timeAgo } from '@/lib/format'
import type { SharedProps } from '@/types'

interface Row {
    id: number
    title: string
    slug: string
    status: string
    url: string | null
    publishedAt: string | null
    updatedAt: string | null
}

interface Props {
    collection: { handle: string; label: string; singular: string }
    sortable: string[]
    filters: { q: string; status: string; sort: string; direction: 'asc' | 'desc' }
    entries: {
        data: Row[]
        total: number
        perPage: number
        currentPage: number
        lastPage: number
    }
}

type StatusFilter = '' | 'published' | 'draft'

/**
 * Every entry in a collection, filtered and paged on the server.
 *
 * The filtering happens on the server, not in the browser: sending the whole
 * collection works at sixty articles and is the decision that becomes a
 * rewrite later. Rows open the editor; the title is a real link so it can be
 * opened in a new tab.
 */
export default function Index({ collection, sortable, filters, entries }: Props) {
    const { cms, nav } = usePage<SharedProps>().props
    const base = `${cms.prefix}/${collection.handle}`
    const counts = nav?.collections[collection.handle]
    const Icon = collectionIcon(collection.handle)

    const [search, setSearch] = useState(filters.q)
    const [selected, setSelected] = useState<Set<number>>(new Set())
    const [confirmDelete, setConfirmDelete] = useState(false)
    const settled = useDebouncedValue(search, 250)
    const firstRender = useRef(true)
    const searchBox = useRef<HTMLInputElement>(null)

    /*
     * Reload when the search settles. `only` keeps the response to the rows
     * and the filters; `preserveState` keeps the caret in the box; `replace`
     * keeps a hundred keystrokes out of history.
     */
    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false

            return
        }

        router.get(
            base,
            { ...queryFrom(filters), q: settled || undefined, page: undefined },
            { only: ['entries', 'filters'], preserveState: true, preserveScroll: true, replace: true },
        )
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [settled])

    // "/" focuses search, as on GitHub and Linear.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement

            if (event.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) && !target.isContentEditable) {
                event.preventDefault()
                searchBox.current?.focus()
            }
        }
        window.addEventListener('keydown', onKey)

        return () => window.removeEventListener('keydown', onKey)
    }, [])

    const go = (changes: Record<string, string | undefined>): void =>
        router.get(base, { ...queryFrom(filters), ...changes }, { only: ['entries', 'filters'], preserveState: true, preserveScroll: true })

    const sortBy = (key: string): void => {
        if (!sortable.includes(key)) return

        go({
            sort: key,
            direction: filters.sort === key && filters.direction === 'desc' ? 'asc' : 'desc',
            page: undefined,
        })
    }

    const allOnPage = entries.data.map((entry) => entry.id)
    const selectedOnPage = allOnPage.filter((id) => selected.has(id)).length

    const toggle = (id: number, on: boolean) =>
        setSelected((current) => {
            const next = new Set(current)
            on ? next.add(id) : next.delete(id)

            return next
        })

    const runBulk = (action: string): void => {
        const ids = [...selected]

        if (ids.length === 0) return

        router.post(`${base}/bulk`, { action, ids }, { preserveScroll: true, onSuccess: () => setSelected(new Set()) })
    }

    const filtered = filters.q !== '' || filters.status !== ''
    const singular = collection.singular.toLowerCase()

    return (
        <>
            <Head title={collection.label} />

            <PageHeader
                title={collection.label}
                description={counts ? `${counts.total} ${counts.total === 1 ? 'entry' : 'entries'}${counts.drafts > 0 ? `, ${counts.drafts} not published` : ''}` : undefined}
                actions={
                    <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => router.visit(`${base}/new`)}>
                        New {singular}
                    </Button>
                }
            />

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <div className="relative w-full sm:w-72">
                    <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-fg-subtle" />
                    <input
                        ref={searchBox}
                        type="search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder={`Search ${collection.label.toLowerCase()}`}
                        className="kit-input pr-8 pl-8 [&::-webkit-search-cancel-button]:hidden"
                    />
                    {search !== '' ? (
                        <button type="button" onClick={() => setSearch('')} className="absolute top-1/2 right-2 -translate-y-1/2 rounded p-0.5 text-fg-subtle hover:text-fg" aria-label="Clear search">
                            <X className="size-3.5" />
                        </button>
                    ) : (
                        <span className="pointer-events-none absolute top-1/2 right-2 -translate-y-1/2"><Kbd>/</Kbd></span>
                    )}
                </div>

                <Segmented<StatusFilter>
                    label="Status"
                    value={(filters.status as StatusFilter) || ''}
                    onChange={(value) => go({ status: value || undefined, page: undefined })}
                    options={[
                        { value: '', label: 'All', count: counts?.total },
                        { value: 'published', label: 'Published', count: counts ? counts.total - counts.drafts : undefined },
                        { value: 'draft', label: 'Drafts', count: counts?.drafts },
                    ]}
                />

                {filtered && (
                    <span className="text-[12.5px] text-fg-subtle">
                        {entries.total} {entries.total === 1 ? 'match' : 'matches'}
                    </span>
                )}
            </div>

            {entries.data.length === 0 ? (
                <Card>
                    {filtered ? (
                        <EmptyState
                            icon={<Search className="size-5" />}
                            title="Nothing matches"
                            description="Try a different search, or clear the filters."
                            action={<Button onClick={() => { setSearch(''); go({ q: undefined, status: undefined, page: undefined }) }}>Clear filters</Button>}
                        />
                    ) : (
                        <EmptyState
                            icon={<Icon className="size-5" />}
                            title={`No ${collection.label.toLowerCase()} yet`}
                            description={`Create the first ${singular} and it will appear here.`}
                            action={<Button variant="primary" icon={<Plus className="size-4" />} onClick={() => router.visit(`${base}/new`)}>New {singular}</Button>}
                        />
                    )}
                </Card>
            ) : (
                    <div className="kit-table-wrap" role="region" aria-label={collection.label} tabIndex={0}>
                        <table className="kit-table min-w-[640px]">
                            <thead>
                                <tr>
                                    <th className="w-10 !pr-0">
                                        <Checkbox
                                            label="Select all on this page"
                                            checked={selectedOnPage > 0 && selectedOnPage === allOnPage.length}
                                            indeterminate={selectedOnPage > 0 && selectedOnPage < allOnPage.length}
                                            onChange={(on) => setSelected((current) => {
                                                const next = new Set(current)
                                                allOnPage.forEach((id) => (on ? next.add(id) : next.delete(id)))

                                                return next
                                            })}
                                        />
                                    </th>
                                    <SortHeader label="Title" column="title" filters={filters} sortable={sortable} onSort={sortBy} />
                                    <SortHeader label="Status" column="status" filters={filters} sortable={sortable} onSort={sortBy} className="w-32" />
                                    <SortHeader label="Published" column="published_at" filters={filters} sortable={sortable} onSort={sortBy} className="w-36" />
                                    <SortHeader label="Updated" column="updated_at" filters={filters} sortable={sortable} onSort={sortBy} className="w-32" />
                                    <th className="w-16"><span className="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                {entries.data.map((entry) => {
                                    const isSelected = selected.has(entry.id)
                                    const edit = `${base}/${entry.id}/edit`

                                    return (
                                        <tr
                                            key={entry.id}
                                            onClick={(event) => {
                                                if ((event.target as HTMLElement).closest('a,button,label,input')) return
                                                router.visit(edit)
                                            }}
                                            aria-selected={isSelected || undefined}
                                            className="group cursor-pointer"
                                        >
                                            <td className="!pr-0">
                                                <Checkbox label={`Select ${entry.title || 'untitled'}`} checked={isSelected} onChange={(on) => toggle(entry.id, on)} />
                                            </td>
                                            <td className="max-w-0 w-full">
                                                <Link href={edit} className="block truncate font-medium text-fg hover:text-accent">
                                                    {entry.title || <span className="text-fg-subtle italic">Untitled</span>}
                                                </Link>
                                                <span className="block truncate font-mono text-[11.5px] text-fg-subtle">/{entry.slug}</span>
                                            </td>
                                            <td><StatusBadge status={entry.status} /></td>
                                            <td className="text-fg-muted tabular-nums">{formatDate(entry.publishedAt) || <span className="text-fg-subtle">—</span>}</td>
                                            <td className="text-fg-muted" title={formatDate(entry.updatedAt)}>{timeAgo(entry.updatedAt)}</td>
                                            <td className="text-right">
                                                {entry.url !== null && entry.status === 'published' && (
                                                    <Tooltip content="View on site">
                                                        <a href={entry.url} target="_blank" rel="noreferrer" className="kit-button opacity-0 group-hover:opacity-100 focus-visible:opacity-100" data-variant="ghost" data-size="sm" data-icon="" aria-label={`View ${entry.title} on site`}>
                                                            <ExternalLink className="size-3.5" />
                                                        </a>
                                                    </Tooltip>
                                                )}
                                            </td>
                                        </tr>
                                    )
                                })}
                            </tbody>
                        </table>
                    </div>
            )}

            <Paginator currentPage={entries.currentPage} lastPage={entries.lastPage} onGo={(page) => go({ page: page === 1 ? undefined : String(page) })} />

            {/* The bulk bar floats over the page while anything is selected. */}
            {selected.size > 0 && (
                <div className="kit-card fixed bottom-5 left-1/2 z-40 !flex-row -translate-x-1/2 items-center gap-1 p-1.5 pl-3.5 shadow-lg animate-pop">
                    <span className="mr-2 text-[13px] font-medium tabular-nums">{selected.size} selected</span>
                    <Button size="sm" variant="ghost" onClick={() => runBulk('publish')}>Publish</Button>
                    <Button size="sm" variant="ghost" onClick={() => runBulk('unpublish')}>Unpublish</Button>
                    <Button size="sm" variant="danger" onClick={() => setConfirmDelete(true)}>Delete</Button>
                    <span className="mx-1 h-5 w-px bg-line" />
                    <Tooltip content="Clear selection">
                        <Button size="icon-sm" variant="ghost" onClick={() => setSelected(new Set())} aria-label="Clear selection">
                            <X className="size-3.5" />
                        </Button>
                    </Tooltip>
                </div>
            )}

            <Confirm
                open={confirmDelete}
                onOpenChange={setConfirmDelete}
                title={`Delete ${selected.size} ${selected.size === 1 ? 'entry' : 'entries'}?`}
                description="They are removed from the site and the admin. Published URLs start returning 404 unless you add redirects."
                confirmLabel="Delete"
                danger
                onConfirm={() => runBulk('delete')}
            />
        </>
    )
}

function SortHeader({ label, column, filters, sortable, onSort, className }: { label: string; column: string; filters: Props['filters']; sortable: string[]; onSort: (key: string) => void; className?: string }) {
    const active = filters.sort === column

    // kit's sortable header: aria-sort on the cell draws the arrow.
    return (
        <th className={className} aria-sort={active ? (filters.direction === 'asc' ? 'ascending' : 'descending') : undefined}>
            {sortable.includes(column) ? (
                <button type="button" className="kit-sort" onClick={() => onSort(column)}>
                    {label}
                </button>
            ) : (
                label
            )}
        </th>
    )
}

function queryFrom(filters: Props['filters']): Record<string, string | undefined> {
    return {
        q: filters.q || undefined,
        status: filters.status || undefined,
        sort: filters.sort,
        direction: filters.direction,
    }
}

