import { Plus, Terminal, Trash2, Sparkles, Copy } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Button, Dialog, cn, toast } from '@/components/ui'

const TYPES = [
    { value: 'text', label: 'Text' },
    { value: 'textarea', label: 'Long text' },
    { value: 'richText', label: 'Rich text' },
    { value: 'number', label: 'Number' },
    { value: 'url', label: 'Link' },
    { value: 'boolean', label: 'Yes / no' },
    { value: 'datetime', label: 'Date' },
    { value: 'select', label: 'Choice' },
    { value: 'media', label: 'Image' },
    { value: 'relation', label: 'Link to entries' },
] as const

interface Row {
    id: number
    name: string
    type: string
    required: boolean
    many: boolean
    target: string
    options: string
}

let nextId = 1
const blank = (type = 'text'): Row => ({ id: nextId++, name: '', type, required: false, many: true, target: '', options: '' })

const snake = (value: string) =>
    value
        .trim()
        .replace(/([a-z0-9])([A-Z])/g, '$1_$2')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')

const singularise = (value: string) => (value.endsWith('ies') ? `${value.slice(0, -3)}y` : value.endsWith('s') && !value.endsWith('ss') ? value.slice(0, -1) : value)

/**
 * Designs a content type and hands back the command that creates it.
 *
 * Content types are code (schema, controller, templates, test), so this does
 * not create one; it produces the exact `cms:collection` command, and a
 * prompt for Claude Code that runs it and finishes the job. The form only
 * offers what the generator accepts, so the command it prints always works.
 */
export function NewContentType({ collections }: { collections: Array<{ handle: string; label: string }> }) {
    const [open, setOpen] = useState(false)
    const [label, setLabel] = useState('')
    const [handleEdited, setHandleEdited] = useState<string | null>(null)
    const [routed, setRouted] = useState(true)
    const [rows, setRows] = useState<Row[]>(() => [{ ...blank('textarea'), name: 'summary' }, { ...blank('richText'), name: 'body' }])

    const handle = handleEdited ?? singularise(snake(label))
    const slugged = handle.replace(/_/g, '-')
    const route = `/${slugged.endsWith('y') && !/[aeiou]y$/.test(slugged) ? `${slugged.slice(0, -1)}ies` : slugged.endsWith('s') ? slugged : `${slugged}s`}`
    const taken = collections.some((c) => c.handle === handle)

    const specs = rows
        .filter((row) => snake(row.name) !== '')
        .map((row) => {
            const name = snake(row.name)
            const argument = row.type === 'select' ? `:${row.options.split(',').map((o) => o.trim()).filter(Boolean).join('|')}` : row.type === 'relation' ? `:${row.target}` : ''

            return `${name}:${row.type}${argument}${row.required ? ':required' : ''}${row.type === 'relation' && row.many ? ':many' : ''}`
        })

    const problems = useMemo(() => {
        const list: string[] = []
        if (handle === '') list.push('Give it a name.')
        if (taken) list.push(`A collection called "${handle}" already exists.`)
        rows.forEach((row) => {
            if (snake(row.name) === '') return
            if (['title', 'slug', 'status', 'published_at', 'seo'].includes(snake(row.name))) list.push(`"${snake(row.name)}" is added automatically; remove it.`)
            if (row.type === 'relation' && row.target === '') list.push(`Pick what "${row.name}" links to.`)
            if (row.type === 'select' && row.options.trim() === '') list.push(`Give "${row.name}" some choices.`)
        })
        const names = rows.map((row) => snake(row.name)).filter(Boolean)
        if (new Set(names).size !== names.length) list.push('Two fields have the same name.')

        return list
    }, [handle, taken, rows])

    const command = [
        `php artisan cms:collection ${handle}`,
        `--label="${label.trim().replace(/"/g, '')}"`,
        specs.length > 0 ? `--fields="${specs.join(',')}"` : '',
        routed ? `--route=${route}` : '--routeless',
    ]
        .filter(Boolean)
        .join(' ')

    const prompt = `Add a new "${label.trim()}" content type to the CMS. Run:\n\n${command}\n\nThen restyle the generated templates in resources/views to match the rest of the site, run the generated test, and tell me what you changed.`

    const copy = (text: string, message: string) =>
        void navigator.clipboard.writeText(text).then(() => toast(message), () => toast('Could not copy', { tone: 'error' }))

    const update = (id: number, changes: Partial<Row>) => setRows((current) => current.map((row) => (row.id === id ? { ...row, ...changes } : row)))

    const input = 'h-8 w-full rounded-[var(--radius-control)] border border-line bg-panel px-2.5 text-[13px] shadow-sm placeholder:text-fg-subtle focus:border-accent focus:ring-3 focus:ring-[var(--accent-ring)] focus:outline-none'

    return (
        <>
            <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>
                New content type
            </Button>

            <Dialog open={open} onOpenChange={setOpen} title="New content type" description="Design it here; one command creates the schema, pages, templates and a test." className="!w-[min(760px,calc(100vw-32px))] !max-h-[84vh] !top-[8vh]">
                <div className="space-y-6">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <label className="block">
                            <span className="mb-1.5 block text-[12.5px] font-medium">Name, as the admin shows it</span>
                            <input className={input} value={label} onChange={(event) => setLabel(event.target.value)} placeholder="Frameworks" autoFocus />
                        </label>
                        <label className="block">
                            <span className="mb-1.5 block text-[12.5px] font-medium">Handle</span>
                            <input className={cn(input, 'font-mono', taken && 'border-danger')} value={handle} onChange={(event) => setHandleEdited(snake(event.target.value))} placeholder="framework" />
                        </label>
                    </div>

                    <label className="flex items-start gap-3 rounded-[var(--radius-card)] border border-line p-3">
                        <input type="checkbox" checked={routed} onChange={(event) => setRouted(event.target.checked)} className="mt-0.5 size-4 accent-[var(--accent)]" />
                        <span className="text-[13px]">
                            <span className="block font-medium">Give it pages on the site</span>
                            <span className="block text-fg-muted">{routed ? <>An index at <code>{route}</code> and a page per entry at <code>{route}/slug</code>.</> : 'Data only, used by other pages (like proof metrics).'}</span>
                        </span>
                    </label>

                    <div>
                        <div className="mb-2 flex items-center justify-between">
                            <span className="text-[12.5px] font-medium">Fields</span>
                            <span className="text-[12px] text-fg-subtle">Title{routed ? ', slug, SEO' : ''} and status are added for you.</span>
                        </div>
                        <div className="space-y-2">
                            {rows.map((row) => (
                                <div key={row.id} className="grid grid-cols-[1fr_150px_auto_auto] items-center gap-2 rounded-[var(--radius-card)] border border-line bg-sunken/40 p-2">
                                    <input className={input} value={row.name} onChange={(event) => update(row.id, { name: event.target.value })} placeholder="field name" aria-label="Field name" />
                                    <select className={cn(input, 'field-input !h-8 !min-h-0 !py-0')} value={row.type} onChange={(event) => update(row.id, { type: event.target.value })} aria-label="Field type">
                                        {TYPES.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
                                    </select>
                                    <label className="flex items-center gap-1.5 px-1 text-[12px] text-fg-muted">
                                        <input type="checkbox" checked={row.required} onChange={(event) => update(row.id, { required: event.target.checked })} className="accent-[var(--accent)]" />
                                        Required
                                    </label>
                                    <button type="button" onClick={() => setRows((current) => current.filter((r) => r.id !== row.id))} className="rounded-md p-1.5 text-fg-subtle hover:bg-hover hover:text-danger" aria-label="Remove field">
                                        <Trash2 className="size-3.5" />
                                    </button>
                                    {row.type === 'relation' && (
                                        <div className="col-span-4 flex items-center gap-2 px-1 pb-1 text-[12.5px] text-fg-muted">
                                            Links to
                                            <select className={cn(input, 'field-input !h-7 !min-h-0 w-48 !py-0')} value={row.target} onChange={(event) => update(row.id, { target: event.target.value })}>
                                                <option value="">Choose…</option>
                                                {collections.map((c) => <option key={c.handle} value={c.handle}>{c.label}</option>)}
                                            </select>
                                            <label className="flex items-center gap-1.5"><input type="checkbox" checked={row.many} onChange={(event) => update(row.id, { many: event.target.checked })} className="accent-[var(--accent)]" /> more than one</label>
                                        </div>
                                    )}
                                    {row.type === 'select' && (
                                        <div className="col-span-4 px-1 pb-1">
                                            <input className={cn(input, '!h-7')} value={row.options} onChange={(event) => update(row.id, { options: event.target.value })} placeholder="Choices, comma separated: backend, frontend, cms" />
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                        <Button size="sm" variant="ghost" icon={<Plus className="size-3.5" />} className="mt-2" onClick={() => setRows((current) => [...current, blank()])}>
                            Add field
                        </Button>
                    </div>

                    {problems.length > 0 ? (
                        <ul className="space-y-1 rounded-[var(--radius-card)] bg-warning-soft px-4 py-3 text-[12.5px] text-warning">
                            {problems.map((problem) => <li key={problem}>{problem}</li>)}
                        </ul>
                    ) : (
                        <div className="space-y-3">
                            <div className="overflow-hidden rounded-[var(--radius-card)] border border-line">
                                <div className="flex items-center justify-between border-b border-line bg-sunken/60 px-3 py-2">
                                    <span className="flex items-center gap-1.5 text-[12px] font-medium"><Terminal className="size-3.5" /> Run in the project</span>
                                    <Button size="sm" variant="ghost" icon={<Copy className="size-3.5" />} onClick={() => copy(command, 'Command copied')}>Copy</Button>
                                </div>
                                <pre className="overflow-x-auto bg-panel px-3 py-2.5 font-mono text-[12px] leading-relaxed whitespace-pre-wrap break-all">{command}</pre>
                            </div>
                            <div className="flex items-center justify-between gap-3 rounded-[var(--radius-card)] border border-accent/25 bg-accent-soft px-3 py-2.5">
                                <span className="flex items-center gap-2 text-[12.5px]"><Sparkles className="size-4 text-accent" /> Or let Claude Code run it and restyle the pages.</span>
                                <Button size="sm" variant="primary" icon={<Copy className="size-3.5" />} onClick={() => copy(prompt, 'Prompt copied, paste it into Claude Code')}>Copy prompt</Button>
                            </div>
                        </div>
                    )}
                </div>
            </Dialog>
        </>
    )
}
