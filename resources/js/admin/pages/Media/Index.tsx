import { Head, Link, router, useForm, usePage } from '@inertiajs/react'
import { ArrowDownUp, Check, CircleAlert, Copy, Crosshair, Download, Grid2x2, Image as ImageIcon, List, Loader2, Search, Trash2, Upload as UploadIcon, X } from 'lucide-react'
import { useEffect, useRef, useState, type DragEvent, type MouseEvent } from 'react'
import Paginator from '@/components/Paginator'
import { Badge, Button, Card, Confirm, Dialog, EmptyState, Menu, MenuContent, MenuRadioGroup, MenuRadioItem, MenuTrigger, PageHeader, Segmented, Tooltip, cn, toast } from '@/components/ui'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { useUploader, type MediaItem, type Upload } from '@/hooks/useUploader'
import { formatDate, timeAgo } from '@/lib/format'
import type { SharedProps } from '@/types'

interface Selected extends MediaItem {
    usedBy: Array<{ id: number; collection: string; title: string; status: string }>
    variants: Array<{ preset: string; format: string; width: number; height: number; bytes: number }>
}

type Filter = 'all' | 'unused' | 'missing-alt' | 'recent'
type Sort = 'newest' | 'oldest' | 'largest' | 'name'

interface Props {
    filters: { q: string; filter: Filter; sort: Sort }
    counts: Record<Filter, number>
    media: { data: MediaItem[]; total: number; perPage: number; currentPage: number; lastPage: number }
    selected: Selected | null
    limits: { maxKb: number; accept: string[] }
}

const VIEW_KEY = 'cg-cms:media-view'

/**
 * The media library.
 *
 * Smart filters instead of folders (an image belongs to many pages, so the
 * useful questions are what is unused, what lacks alt text and what is new),
 * drop anywhere to upload, and the selected image in a side sheet with its
 * selection in the URL (`?selected=`), so a link to one image works.
 *
 * Every reload is partial: paging asks for `media`, opening an image asks for
 * `selected`, so neither pays for the other's usage scan.
 */
export default function Index({ filters, counts, media, selected, limits }: Props) {
    const { cms } = usePage<SharedProps>().props
    const base = `${cms.prefix}/media`

    const [q, setQ] = useState(filters.q)
    const settled = useDebouncedValue(q, 250)
    const [dragging, setDragging] = useState(false)
    const dragDepth = useRef(0)
    const fileInput = useRef<HTMLInputElement>(null)
    const [view, setView] = useState<'grid' | 'list'>(() => {
        try {
            return localStorage.getItem(VIEW_KEY) === 'list' ? 'list' : 'grid'
        } catch {
            return 'grid'
        }
    })

    useEffect(() => {
        try {
            localStorage.setItem(VIEW_KEY, view)
        } catch {
            // Not remembered; harmless.
        }
    }, [view])

    const visit = (params: Record<string, string | number | undefined>, only: string[]): void =>
        router.get(
            base,
            {
                q: filters.q || undefined,
                filter: filters.filter === 'all' ? undefined : filters.filter,
                sort: filters.sort === 'newest' ? undefined : filters.sort,
                selected: selected?.id,
                ...params,
            },
            { only, preserveState: true, preserveScroll: true, replace: true },
        )

    useEffect(() => {
        if (settled !== filters.q) visit({ q: settled || undefined, page: undefined }, ['media', 'filters'])
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [settled])

    const uploader = useUploader({
        url: base,
        maxKb: limits.maxKb,
        onFinished: () => router.reload({ only: ['media', 'counts'] }),
    })

    // Drop anywhere on the page. A depth counter, because dragenter and
    // dragleave fire for every child the pointer crosses.
    const onDragEnter = (event: DragEvent) => {
        if (!event.dataTransfer.types.includes('Files')) return
        dragDepth.current += 1
        setDragging(true)
    }
    const onDragLeave = () => {
        dragDepth.current = Math.max(0, dragDepth.current - 1)
        if (dragDepth.current === 0) setDragging(false)
    }
    const onDrop = (event: DragEvent) => {
        event.preventDefault()
        dragDepth.current = 0
        setDragging(false)
        if (event.dataTransfer.files.length > 0) uploader.add(event.dataTransfer.files)
    }

    const searching = filters.q !== '' || filters.filter !== 'all'

    return (
        <div onDragEnter={onDragEnter} onDragLeave={onDragLeave} onDragOver={(event) => event.preventDefault()} onDrop={onDrop} className="min-h-[calc(100dvh-10rem)]">
            <Head title="Media" />

            <PageHeader
                title="Media"
                description={`${counts.all} ${counts.all === 1 ? 'image' : 'images'}. Drop files anywhere on this page to upload.`}
                actions={
                    <>
                        <input
                            ref={fileInput}
                            type="file"
                            multiple
                            accept={limits.accept.join(',')}
                            className="sr-only"
                            onChange={(event) => {
                                if (event.target.files && event.target.files.length > 0) uploader.add(event.target.files)
                                event.target.value = ''
                            }}
                        />
                        <Button variant="primary" icon={<UploadIcon className="size-4" />} onClick={() => fileInput.current?.click()}>
                            Upload
                        </Button>
                    </>
                }
            />

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <div className="relative w-full sm:w-64">
                    <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-fg-subtle" />
                    <input
                        type="search"
                        value={q}
                        onChange={(event) => setQ(event.target.value)}
                        placeholder="Filename or alt text"
                        aria-label="Search media"
                        className="h-8 w-full rounded-[var(--radius-control)] border border-line bg-panel pr-2 pl-8 text-[13px] shadow-sm placeholder:text-fg-subtle hover:border-line-strong focus:border-accent focus:ring-3 focus:ring-[var(--accent-ring)] focus:outline-none"
                    />
                </div>

                <Segmented<Filter>
                    label="Show"
                    value={filters.filter}
                    onChange={(value) => visit({ filter: value === 'all' ? undefined : value, page: undefined }, ['media', 'filters'])}
                    options={[
                        { value: 'all', label: 'All', count: counts.all },
                        { value: 'unused', label: 'Unused', count: counts.unused },
                        { value: 'missing-alt', label: 'No alt text', count: counts['missing-alt'] },
                        { value: 'recent', label: 'Last 30 days', count: counts.recent },
                    ]}
                />

                <div className="ml-auto flex items-center gap-1.5">
                    <Menu>
                        <MenuTrigger asChild>
                            <Button size="md" variant="ghost" icon={<ArrowDownUp className="size-3.5" />}>
                                {{ newest: 'Newest', oldest: 'Oldest', largest: 'Largest', name: 'Name' }[filters.sort]}
                            </Button>
                        </MenuTrigger>
                        <MenuContent className="w-44">
                            <MenuRadioGroup value={filters.sort} onValueChange={(value) => visit({ sort: value === 'newest' ? undefined : value, page: undefined }, ['media', 'filters'])}>
                                <MenuRadioItem value="newest">Newest first</MenuRadioItem>
                                <MenuRadioItem value="oldest">Oldest first</MenuRadioItem>
                                <MenuRadioItem value="largest">Largest file</MenuRadioItem>
                                <MenuRadioItem value="name">Name</MenuRadioItem>
                            </MenuRadioGroup>
                        </MenuContent>
                    </Menu>
                    <div className="flex rounded-[var(--radius-control)] bg-sunken p-0.5">
                        {(['grid', 'list'] as const).map((mode) => (
                            <Tooltip key={mode} content={mode === 'grid' ? 'Grid' : 'List'}>
                                <button
                                    type="button"
                                    onClick={() => setView(mode)}
                                    aria-pressed={view === mode}
                                    aria-label={`${mode} view`}
                                    className={cn('inline-flex size-7 items-center justify-center rounded-[5px]', view === mode ? 'bg-panel text-fg shadow-sm' : 'text-fg-subtle hover:text-fg')}
                                >
                                    {mode === 'grid' ? <Grid2x2 className="size-3.5" /> : <List className="size-3.5" />}
                                </button>
                            </Tooltip>
                        ))}
                    </div>
                </div>
            </div>

            {media.data.length === 0 ? (
                <Card>
                    {searching ? (
                        <EmptyState icon={<Search className="size-5" />} title="Nothing matches" description="Try another search or filter." action={<Button onClick={() => { setQ(''); visit({ q: undefined, filter: undefined, page: undefined }, ['media', 'filters']) }}>Show everything</Button>} />
                    ) : (
                        <EmptyState
                            icon={<ImageIcon className="size-5" />}
                            title="No images yet"
                            description="Drop images anywhere on this page, or choose them. Every size the site needs is made on the server."
                            action={<Button variant="primary" icon={<UploadIcon className="size-4" />} onClick={() => fileInput.current?.click()}>Upload images</Button>}
                        />
                    )}
                </Card>
            ) : view === 'grid' ? (
                <ul className="grid grid-cols-[repeat(auto-fill,minmax(160px,1fr))] gap-3">
                    {media.data.map((item) => (
                        <li key={item.id}>
                            <button
                                type="button"
                                onClick={() => visit({ selected: item.id }, ['selected'])}
                                aria-pressed={selected?.id === item.id}
                                className={cn(
                                    'group relative block aspect-square w-full overflow-hidden rounded-[var(--radius-card)] border bg-sunken shadow-sm transition-[border-color,box-shadow]',
                                    selected?.id === item.id ? 'border-accent ring-3 ring-[var(--accent-ring)]' : 'border-line hover:border-line-strong hover:shadow-md',
                                )}
                            >
                                <img src={item.thumb} alt="" loading="lazy" className="size-full object-cover transition-transform duration-300 group-hover:scale-[1.03]" />
                                <span className="absolute top-2 left-2 flex gap-1">
                                    {!item.alt && <Badge tone="warning" className="shadow-sm">No alt</Badge>}
                                    {item.usage === 0 && <Badge className="bg-panel/90 shadow-sm">Unused</Badge>}
                                </span>
                                <span className="absolute inset-x-0 bottom-0 translate-y-1 bg-gradient-to-t from-black/70 to-transparent px-2.5 pt-6 pb-2 text-left opacity-0 transition-[opacity,transform] group-hover:translate-y-0 group-hover:opacity-100">
                                    <span className="block truncate text-[12px] font-medium text-white">{item.filename}</span>
                                    <span className="block text-[11px] text-white/70">{item.width} × {item.height}</span>
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            ) : (
                <Card className="overflow-hidden">
                    <table className="w-full text-[13px]">
                        <thead>
                            <tr className="border-b border-line bg-sunken/60 text-left text-[11.5px] text-fg-subtle">
                                <th className="py-2 pl-4 font-medium">File</th>
                                <th className="hidden py-2 pr-4 font-medium md:table-cell">Alt text</th>
                                <th className="py-2 pr-4 font-medium">Size</th>
                                <th className="py-2 pr-4 font-medium">Used</th>
                                <th className="hidden py-2 pr-4 font-medium sm:table-cell">Added</th>
                            </tr>
                        </thead>
                        <tbody>
                            {media.data.map((item) => (
                                <tr key={item.id} onClick={() => visit({ selected: item.id }, ['selected'])} className={cn('cursor-pointer border-b border-line last:border-b-0', selected?.id === item.id ? 'bg-accent-soft/60' : 'hover:bg-hover/60')}>
                                    <td className="py-2 pl-4">
                                        <div className="flex items-center gap-3">
                                            <img src={item.thumb} alt="" loading="lazy" className="size-10 shrink-0 rounded-md border border-line object-cover" />
                                            <div className="min-w-0">
                                                <div className="truncate font-medium">{item.filename}</div>
                                                <div className="text-[11.5px] text-fg-subtle">{item.width} × {item.height}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td className="hidden max-w-xs py-2 pr-4 md:table-cell">{item.alt ? <span className="line-clamp-1 text-fg-muted">{item.alt}</span> : <Badge tone="warning">Missing</Badge>}</td>
                                    <td className="py-2 pr-4 text-fg-muted tabular-nums">{formatBytes(item.bytes)}</td>
                                    <td className="py-2 pr-4 text-fg-muted tabular-nums">{item.usage === 0 ? <Badge>Unused</Badge> : item.usage}</td>
                                    <td className="hidden py-2 pr-4 text-fg-muted sm:table-cell">{timeAgo(item.createdAt)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
            )}

            <Paginator currentPage={media.currentPage} lastPage={media.lastPage} onGo={(page) => visit({ page }, ['media'])} />

            {dragging && (
                <div className="pointer-events-none fixed inset-0 z-50 flex items-center justify-center bg-canvas/70 backdrop-blur-sm animate-in">
                    <div className="flex flex-col items-center gap-3 rounded-[var(--radius-dialog)] border-2 border-dashed border-accent bg-elevated px-16 py-12 shadow-lg">
                        <span className="flex size-12 items-center justify-center rounded-full bg-accent-soft text-accent"><UploadIcon className="size-5" /></span>
                        <p className="text-[15px] font-semibold">Drop to upload</p>
                        <p className="text-[12.5px] text-fg-muted">JPEG, PNG, WebP or GIF, up to {Math.round(limits.maxKb / 1024)} MB each</p>
                    </div>
                </div>
            )}

            <UploadTray uploads={uploader.uploads} onClear={uploader.clear} />

            <Dialog
                open={selected !== null}
                onOpenChange={(open) => !open && visit({ selected: undefined }, ['selected'])}
                variant="sheet"
                title={selected?.filename ?? ''}
                description={selected ? `${selected.width} × ${selected.height} · ${formatBytes(selected.bytes)} · added ${formatDate(selected.createdAt)}` : undefined}
            >
                {selected && <Detail key={selected.id} media={selected} base={base} prefix={cms.prefix} />}
            </Dialog>
        </div>
    )
}

function UploadTray({ uploads, onClear }: { uploads: Upload[]; onClear: () => void }) {
    if (uploads.length === 0) return null

    const active = uploads.filter((upload) => upload.state === 'queued' || upload.state === 'uploading').length

    return (
        <div className="fixed right-4 bottom-4 z-40 w-[min(360px,calc(100vw-32px))] overflow-hidden rounded-[var(--radius-card)] border border-line bg-elevated shadow-lg animate-slide-in" aria-live="polite">
            <div className="flex items-center justify-between border-b border-line px-3.5 py-2.5">
                <span className="text-[13px] font-semibold">{active > 0 ? `Uploading ${active} of ${uploads.length}` : `${uploads.length} ${uploads.length === 1 ? 'upload' : 'uploads'} finished`}</span>
                {active === 0 && (
                    <button type="button" onClick={onClear} className="rounded p-1 text-fg-subtle hover:bg-hover hover:text-fg" aria-label="Dismiss">
                        <X className="size-3.5" />
                    </button>
                )}
            </div>
            <ul className="max-h-64 overflow-y-auto">
                {uploads.map((upload) => (
                    <li key={upload.key} className="flex items-center gap-3 border-b border-line px-3.5 py-2 text-[12.5px] last:border-b-0">
                        <span className="shrink-0">
                            {upload.state === 'failed' ? <CircleAlert className="size-4 text-danger" /> : upload.state === 'done' || upload.state === 'existing' ? <Check className="size-4 text-success" /> : <Loader2 className="size-4 animate-spin text-fg-subtle" />}
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className="block truncate font-medium">{upload.name}</span>
                            {upload.state === 'uploading' || upload.state === 'queued' ? (
                                <span className="mt-1 block h-1 overflow-hidden rounded-full bg-sunken">
                                    <span className="block h-full rounded-full bg-accent transition-[width]" style={{ width: `${upload.progress}%` }} />
                                </span>
                            ) : (
                                <span className={cn('block', upload.state === 'failed' ? 'text-danger' : 'text-fg-subtle')}>
                                    {upload.state === 'failed' ? (upload.error ?? 'Failed') : upload.state === 'existing' ? 'Already in the library' : 'Uploaded'}
                                </span>
                            )}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    )
}

function Detail({ media, base, prefix }: { media: Selected; base: string; prefix: string }) {
    const form = useForm({ alt: media.alt ?? '' })
    const [confirmDelete, setConfirmDelete] = useState(false)

    /*
        The focal point, set by clicking the image. Stored as fractions, so it
        means the same at every size. Saving regenerates every variant around
        it on the queue, which is why it is separate from the alt text.
    */
    const setFocus = (event: MouseEvent<HTMLButtonElement>): void => {
        const box = event.currentTarget.getBoundingClientRect()
        const x = Math.min(1, Math.max(0, (event.clientX - box.left) / box.width))
        const y = Math.min(1, Math.max(0, (event.clientY - box.top) / box.height))

        router.put(`${base}/${media.id}`, { focal_x: Number(x.toFixed(3)), focal_y: Number(y.toFixed(3)) }, { preserveScroll: true, preserveState: true, only: ['selected', 'media', 'flash'] })
    }

    const copyUrl = () => {
        const url = new URL(media.preview, window.location.origin).toString()

        void navigator.clipboard.writeText(url).then(() => toast('Image URL copied'), () => toast('Could not copy', { tone: 'error' }))
    }

    const used = media.usedBy.length > 0

    return (
        <div className="space-y-6">
            <div>
                <button type="button" onClick={setFocus} className="group relative block w-full cursor-crosshair overflow-hidden rounded-[var(--radius-card)] border border-line bg-[repeating-conic-gradient(var(--sunken)_0_25%,var(--panel)_0_50%)] bg-[length:16px_16px]" title="Click to set the focal point">
                    <img src={media.preview} alt={media.alt ?? ''} className="block h-auto w-full" />
                    <span className="pointer-events-none absolute size-6 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white shadow-[0_0_0_2px_rgb(0_0_0/0.5),0_2px_8px_rgb(0_0_0/0.4)] transition-[left,top] duration-200" style={{ left: `${media.focalX * 100}%`, top: `${media.focalY * 100}%` }} aria-hidden />
                </button>
                <p className="mt-2 flex items-center gap-1.5 text-[12px] text-fg-subtle">
                    <Crosshair className="size-3.5" />
                    Click the image to set the focal point. Every crop keeps it in frame.
                    {!media.processed && ' Sizes are still being made.'}
                </p>
            </div>

            <form
                onSubmit={(event) => {
                    event.preventDefault()
                    form.put(`${base}/${media.id}`, { preserveScroll: true, preserveState: true, only: ['selected', 'media', 'counts', 'flash'], onSuccess: () => form.setDefaults() })
                }}
                className="space-y-2"
            >
                <label htmlFor="media-alt" className="flex items-center justify-between text-[12.5px] font-medium">
                    Alt text
                    <span className="text-[11.5px] font-normal text-fg-subtle tabular-nums">{form.data.alt.length} / 255</span>
                </label>
                <textarea
                    id="media-alt"
                    rows={3}
                    value={form.data.alt}
                    maxLength={255}
                    placeholder="Describe what the image shows, for someone who cannot see it."
                    onChange={(event) => form.setData('alt', event.target.value)}
                    className="field-input field-textarea"
                />
                {form.errors.alt !== undefined && <p className="field-error">{form.errors.alt}</p>}
                <div className="flex items-center justify-between gap-3">
                    <p className="text-[12px] text-fg-subtle">The default wherever this image is used.</p>
                    <Button type="submit" size="sm" variant="primary" loading={form.processing} disabled={!form.isDirty}>Save</Button>
                </div>
            </form>

            <div className="flex gap-2">
                <Button size="sm" icon={<Copy className="size-3.5" />} onClick={copyUrl}>Copy URL</Button>
                <a href={media.preview} download={media.filename} className="button-quiet !h-7 !text-[12.5px]"><Download className="size-3.5" />Download</a>
            </div>

            <section>
                <h3 className="mb-2 text-[12.5px] font-semibold">{used ? `Used on ${media.usedBy.length} ${media.usedBy.length === 1 ? 'entry' : 'entries'}` : 'Not used anywhere'}</h3>
                {used ? (
                    <ul className="divide-y divide-line rounded-[var(--radius-card)] border border-line">
                        {media.usedBy.map((entry) => (
                            <li key={entry.id}>
                                <Link href={`${prefix}/${entry.collection}/${entry.id}/edit`} className="flex items-center justify-between gap-3 px-3 py-2 text-[13px] hover:bg-hover">
                                    <span className="truncate font-medium">{entry.title}</span>
                                    <span className="shrink-0 text-[12px] text-fg-subtle">{entry.collection}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-[12.5px] text-fg-subtle">Safe to delete. Nothing on the site will break.</p>
                )}
            </section>

            <section>
                <h3 className="mb-2 text-[12.5px] font-semibold">Sizes</h3>
                <dl className="grid grid-cols-2 gap-x-4 gap-y-1.5 text-[12.5px]">
                    <dt className="text-fg-subtle">Original</dt>
                    <dd className="text-right tabular-nums">{media.width} × {media.height}, {formatBytes(media.bytes)}</dd>
                    <dt className="text-fg-subtle">Type</dt>
                    <dd className="text-right">{media.mime}</dd>
                    <dt className="text-fg-subtle">Variants</dt>
                    <dd className="text-right tabular-nums">{media.variants.length}, {formatBytes(media.variants.reduce((sum, v) => sum + v.bytes, 0))} total</dd>
                </dl>
                {media.variants.length > 0 && (
                    <div className="mt-2 flex flex-wrap gap-1">
                        {media.variants.map((variant) => (
                            <span key={`${variant.preset}-${variant.format}`} className="inline-flex h-5 items-center rounded border border-line px-1.5 text-[11px] text-fg-muted" title={`${variant.width} × ${variant.height}, ${formatBytes(variant.bytes)}`}>
                                {variant.preset}.{variant.format}
                            </span>
                        ))}
                    </div>
                )}
            </section>

            <div className="border-t border-line pt-4">
                {used ? (
                    <p className="text-[12px] text-fg-subtle">To delete this image, remove it from the entries above first.</p>
                ) : (
                    <Button size="sm" variant="danger" icon={<Trash2 className="size-3.5" />} onClick={() => setConfirmDelete(true)}>Delete image</Button>
                )}
            </div>

            <Confirm open={confirmDelete} onOpenChange={setConfirmDelete} title={`Delete ${media.filename}?`} description="The original and every size made from it are removed. This cannot be undone." confirmLabel="Delete" danger onConfirm={() => router.delete(`${base}/${media.id}`)} />
        </div>
    )
}

export function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}
