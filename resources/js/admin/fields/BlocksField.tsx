import { usePage } from '@inertiajs/react'
import { Eye, PanelsTopLeft, PenLine } from 'lucide-react'
import { useEffect, useState } from 'react'
import { BlockPreviewFrame } from '../components/BlockPreviewFrame'
import ItemList, { newKey, withoutKeys, type Item } from '../components/ItemList'
import { Dialog, cn } from '../components/ui'
import type { SharedProps } from '../types'
import FieldRenderer from './FieldRenderer'
import type { FieldProps, FieldSchema } from './types'

interface StoredBlock {
    type?: string
    _type?: string
    data?: Record<string, unknown>
    [key: string]: unknown
}

type BlockRow = Item & {
    type: string
    data: Record<string, unknown>
}

/**
 * The block canvas.
 *
 * An ordered list of typed sections, each rendering its own fields through the
 * same registry a top-level field uses. This is the flexible-content half of
 * what ACF gives you, with one deliberate difference: the layouts are declared
 * in PHP rather than built through a UI, so a block type is a class plus a
 * Blade view and lives in version control.
 *
 * It shares its list with the repeater field. They look like different
 * features and are the same problem, which is why neither has its own copy of
 * drag ordering or collapse state.
 */
export default function BlocksField({ field, value, onChange, error }: FieldProps<StoredBlock[]>) {
    const allowed = field.allowedBlocks ?? []
    const { cms } = usePage<SharedProps>().props
    const [rows, setRows] = useState<BlockRow[]>(() => normalise(value))
    const [previewing, setPreviewing] = useState<Set<string>>(new Set())
    const [pagePreview, setPagePreview] = useState(false)

    const togglePreview = (key: string) =>
        setPreviewing((current) => {
            const next = new Set(current)
            next.has(key) ? next.delete(key) : next.add(key)

            return next
        })

    useEffect(() => {
        const mine = JSON.stringify(withoutKeys(rows))
        const theirs = JSON.stringify(normalise(value).map(({ _key: _k, ...rest }) => rest))

        if (mine !== theirs) {
            setRows(normalise(value))
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [value])

    const update = (next: BlockRow[]): void => {
        setRows(next)
        onChange(withoutKeys(next) as StoredBlock[])
    }

    const setCell = (index: number, name: string, cell: unknown): void =>
        update(
            rows.map((row, i) => (i === index ? { ...row, data: { ...row.data, [name]: cell } } : row)),
        )

    const schemaFor = (handle: string): FieldSchema[] =>
        allowed.find((block) => block.handle === handle)?.fields ?? []

    const labelFor = (handle: string): string =>
        allowed.find((block) => block.handle === handle)?.label ?? handle

    if (allowed.length === 0) {
        return <p className="field-help">This field allows no block types.</p>
    }

    return (
        <>
            {rows.length > 0 && (
                <div className="mb-2 flex justify-end">
                    <button type="button" onClick={() => setPagePreview(true)} className="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-[12px] font-medium text-fg-muted hover:bg-hover hover:text-fg">
                        <PanelsTopLeft className="size-3.5" />
                        Preview all {rows.length} blocks
                    </button>
                </div>
            )}

            <ItemList<BlockRow>
                items={rows}
                onChange={update}
                summary={(row) => (
                    <>
                        <span className="itemlist-title">{labelFor(row.type)}</span>
                        <span className="itemlist-hint">{blockHint(row)}</span>
                    </>
                )}
            >
                {(row, index) => {
                    const fields = schemaFor(row.type)

                    if (fields.length === 0) {
                        // A stored block whose type is no longer declared.
                        // Shown rather than dropped: the content is still
                        // there, and silently discarding it on the next save
                        // would be the worst possible response.
                        return (
                            <div className="field-unknown">
                                <p className="field-warn">
                                    Unknown block type <code>{row.type}</code>. Its content is
                                    kept but cannot be edited here, and it will not render.
                                </p>
                                <pre className="field-raw">{JSON.stringify(row.data, null, 2)}</pre>
                            </div>
                        )
                    }

                    const showing = previewing.has(row._key)

                    return (
                        <div className="itemlist-fields">
                            <div className="flex w-fit rounded-[var(--radius-control)] bg-sunken p-0.5" role="tablist">
                                {[
                                    { on: false, label: 'Fields', icon: <PenLine className="size-3.5" /> },
                                    { on: true, label: 'Preview', icon: <Eye className="size-3.5" /> },
                                ].map((mode) => (
                                    <button
                                        key={mode.label}
                                        type="button"
                                        role="tab"
                                        aria-selected={showing === mode.on}
                                        onClick={() => showing !== mode.on && togglePreview(row._key)}
                                        className={cn('inline-flex h-7 items-center gap-1.5 rounded-[5px] px-2.5 text-[12.5px] font-medium', showing === mode.on ? 'bg-panel text-fg shadow-sm' : 'text-fg-muted hover:text-fg')}
                                    >
                                        {mode.icon}
                                        {mode.label}
                                    </button>
                                ))}
                            </div>

                            {showing && <BlockPreviewFrame blocks={[{ type: row.type, data: row.data }]} prefix={cms.prefix} />}

                            {!showing && fields.map((sub) => (
                                <FieldRenderer
                                    key={sub.name}
                                    field={{ ...sub, name: `${field.name}.${index}.${sub.name}` }}
                                    value={row.data[sub.name]}
                                    values={row.data}
                                    onChange={(_name, cell) => setCell(index, sub.name, cell)}
                                />
                            ))}
                        </div>
                    )
                }}
            </ItemList>

            <div className="block-picker">
                <span className="field-help">Add a block:</span>
                {allowed.map((block) => (
                    <button
                        key={block.handle}
                        type="button"
                        className="button-quiet"
                        onClick={() =>
                            update([
                                ...rows,
                                { _key: newKey(), type: block.handle, data: blankData(block.fields) },
                            ])
                        }
                    >
                        {block.label}
                    </button>
                ))}
            </div>

            {error !== undefined && <p className="field-error">{error}</p>}

            <Dialog open={pagePreview} onOpenChange={setPagePreview} title="Page preview" description="Every block, in order, as the site renders it. Unsaved changes included." className="!w-[min(1200px,calc(100vw-32px))] !max-h-[88vh] !top-[6vh]">
                {pagePreview && <BlockPreviewFrame blocks={rows.map((row) => ({ type: row.type, data: row.data }))} prefix={cms.prefix} className="border-0" />}
            </Dialog>
        </>
    )
}

/**
 * Stored blocks, keyed and in the canonical shape.
 *
 * The legacy CMS wrote fields flat on the block and nested under `data` at
 * different times, and the Phase 6 import will produce a mix, so this reads
 * both and writes one. BlockRenderer and ValidBlocks normalise the same way,
 * and the canvas is where the shape actually gets corrected: a page saved
 * through the editor comes back canonical.
 */
function normalise(value: unknown): BlockRow[] {
    if (!Array.isArray(value)) {
        return []
    }

    return value
        .filter((block): block is StoredBlock => typeof block === 'object' && block !== null)
        .map((block) => {
            const { type, _type, data, ...flat } = block

            return {
                _key: newKey(),
                type: type ?? _type ?? 'unknown',
                data: data ?? (flat as Record<string, unknown>),
            }
        })
}

function blankData(fields: FieldSchema[]): Record<string, unknown> {
    const data: Record<string, unknown> = {}

    for (const field of fields) {
        data[field.name] =
            field.default ??
            (field.type === 'boolean'
                ? false
                : field.type === 'repeater' || field.type === 'blocks'
                  ? []
                  : field.type === 'relation'
                    ? field.many
                        ? []
                        : null
                    : ['number', 'datetime', 'media', 'richText'].includes(field.type)
                      ? null
                      : '')
    }

    return data
}

/** The first bit of text in the block, so a collapsed row is identifiable. */
function blockHint(row: BlockRow): string {
    for (const key of ['heading', 'title', 'label', 'form_slug']) {
        const value = row.data[key]

        if (typeof value === 'string' && value.trim() !== '') {
            return value.length > 60 ? `${value.slice(0, 60)}...` : value
        }
    }

    return ''
}
