import { Head, Link, usePage } from '@inertiajs/react'
import { Blocks, ChevronRight, Copy, CornerDownRight, Eye, FileCode2, Info, Layers, PanelRight, Search } from 'lucide-react'
import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { Badge, Card, EmptyState, PageHeader, Tooltip, cn, toast } from '@/components/ui'
import { NewContentType } from '@/components/NewContentType'
import { collectionIcon } from '@/layouts/nav'
import type { SharedProps } from '@/types'

interface Location { file: string; line: number }

interface FieldInfo {
    name: string
    type: string
    label: string
    help: string | null
    required: boolean
    maxLength: number | null
    default: string | null
    options: string[]
    lint: string[]
    relatedCollection: string | null
    many: boolean
    requireAlt: boolean
    preset: string | null
    slugSource: string | null
    tab: string | null
    sidebar: boolean
    condition: { field: string; values: Array<string | number | boolean> } | null
    uses: string | null
    min: number | null
    max: number | null
    blocks: string[]
    fields: FieldInfo[]
    definedAt?: Location | null
}

interface CollectionInfo {
    handle: string
    label: string
    route: string | null
    indexPath: string | null
    order: string
    seoTitle: string | null
    entries: number
    definedAt: Location | null
    fields: FieldInfo[]
}

interface BlockInfo {
    handle: string
    label: string
    view: string
    viewExists: boolean
    dependsOn: string[]
    usedIn: string[]
    definedAt: Location | null
    fields: FieldInfo[]
}

interface GroupInfo {
    name: string
    definedAt: Location | null
    fields: FieldInfo[]
    usedIn: string[]
}

interface Props {
    schemaPath: string
    collections: CollectionInfo[]
    blocks: BlockInfo[]
    groups: GroupInfo[]
}

type Selection = { kind: 'collection' | 'block' | 'group'; id: string }

/**
 * The content model, read only.
 *
 * Built from the same registries the editor and validator use, so what it
 * shows is what the site does. Changing it is a change to the schema file;
 * every item says where it lives and copies as code, which is the hand-off
 * to an editor or to Claude Code.
 */
export default function Index({ schemaPath, collections, blocks, groups }: Props) {
    const { cms } = usePage<SharedProps>().props
    const [filter, setFilter] = useState('')
    const [selection, setSelection] = useState<Selection>(() => fromHash() ?? { kind: 'collection', id: collections[0]?.handle ?? '' })

    useEffect(() => {
        history.replaceState(null, '', `#${selection.kind}-${selection.id}`)
    }, [selection])

    const needle = filter.trim().toLowerCase()
    const matches = (...values: Array<string | null | undefined>) => needle === '' || values.some((value) => value?.toLowerCase().includes(needle))

    const selectedCollection = selection.kind === 'collection' ? collections.find((c) => c.handle === selection.id) : undefined
    const selectedBlock = selection.kind === 'block' ? blocks.find((b) => b.handle === selection.id) : undefined
    const selectedGroup = selection.kind === 'group' ? groups.find((g) => g.name === selection.id) : undefined

    return (
        <>
            <Head title="Content model" />

            <PageHeader
                title="Content model"
                description={
                    <>
                        Every collection, field and block on the site, read from <code className="rounded bg-sunken px-1.5 py-0.5 text-[12px]">{schemaPath}</code>. Read only: to change it, edit that file or ask Claude Code.
                    </>
                }
                actions={<NewContentType collections={collections.map((c) => ({ handle: c.handle, label: c.label }))} />}
            />

            <div className="grid gap-6 lg:grid-cols-[260px_minmax(0,1fr)]">
                <nav className="lg:sticky lg:top-20 lg:max-h-[calc(100dvh-7rem)] lg:overflow-y-auto" aria-label="Content model">
                    <div className="relative mb-4">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-fg-subtle" />
                        <input
                            type="search"
                            value={filter}
                            onChange={(event) => setFilter(event.target.value)}
                            placeholder="Filter"
                            className="h-8 w-full rounded-[var(--radius-control)] border border-line bg-panel pr-2 pl-8 text-[13px] shadow-sm placeholder:text-fg-subtle focus:border-accent focus:ring-3 focus:ring-[var(--accent-ring)] focus:outline-none"
                        />
                    </div>

                    <RailGroup label="Collections" count={collections.length}>
                        {collections.filter((c) => matches(c.label, c.handle, ...c.fields.map((f) => f.name))).map((collection) => {
                            const Icon = collectionIcon(collection.handle)

                            return (
                                <RailItem key={collection.handle} active={selection.kind === 'collection' && selection.id === collection.handle} onClick={() => setSelection({ kind: 'collection', id: collection.handle })} icon={<Icon />} meta={String(collection.fields.length)}>
                                    {collection.label}
                                </RailItem>
                            )
                        })}
                    </RailGroup>

                    <RailGroup label="Blocks" count={blocks.length}>
                        {blocks.filter((b) => matches(b.label, b.handle, ...b.fields.map((f) => f.name))).map((block) => (
                            <RailItem key={block.handle} active={selection.kind === 'block' && selection.id === block.handle} onClick={() => setSelection({ kind: 'block', id: block.handle })} icon={<Blocks />} meta={String(block.fields.length)}>
                                {block.label}
                            </RailItem>
                        ))}
                    </RailGroup>

                    <RailGroup label="Field groups" count={groups.length}>
                        {groups.length === 0 && <p className="px-2.5 py-1 text-[12px] text-fg-subtle">None defined yet.</p>}
                        {groups.filter((g) => matches(g.name)).map((group) => (
                            <RailItem key={group.name} active={selection.kind === 'group' && selection.id === group.name} onClick={() => setSelection({ kind: 'group', id: group.name })} icon={<Layers />} meta={String(group.fields.length)}>
                                {group.name}
                            </RailItem>
                        ))}
                    </RailGroup>
                </nav>

                <div className="min-w-0 animate-in" key={`${selection.kind}-${selection.id}`}>
                    {selectedCollection && (
                        <Detail
                            icon={(() => { const Icon = collectionIcon(selectedCollection.handle); return <Icon className="size-5" /> })()}
                            title={selectedCollection.label}
                            handle={selectedCollection.handle}
                            definedAt={selectedCollection.definedAt}
                            facts={[
                                ['URL', selectedCollection.route ?? 'Not routed (used by other pages)'],
                                ['Index page', selectedCollection.indexPath ?? 'None'],
                                ['Ordered by', selectedCollection.order],
                                ['SEO title', selectedCollection.seoTitle ?? 'Site default'],
                            ]}
                            action={
                                <Link href={`${cms.prefix}/${selectedCollection.handle}`} className="inline-flex h-8 items-center gap-1.5 rounded-[var(--radius-control)] border border-line bg-panel px-3 text-[13px] font-medium shadow-sm hover:bg-hover">
                                    {selectedCollection.entries} {selectedCollection.entries === 1 ? 'entry' : 'entries'} <ChevronRight className="size-3.5 text-fg-subtle" />
                                </Link>
                            }
                        >
                            <FieldTable fields={selectedCollection.fields} showSource showPlacement onSelectBlock={(handle) => setSelection({ kind: 'block', id: handle })} onSelectGroup={(name) => setSelection({ kind: 'group', id: name })} />
                        </Detail>
                    )}

                    {selectedBlock && (
                        <Detail
                            icon={<Blocks className="size-5" />}
                            title={selectedBlock.label}
                            handle={selectedBlock.handle}
                            definedAt={selectedBlock.definedAt}
                            facts={[
                                ['Template', <span key="v" className="inline-flex items-center gap-1.5"><code className="text-[12px]">{selectedBlock.view}</code>{!selectedBlock.viewExists && <Badge tone="danger">missing</Badge>}</span>],
                                ['Used in', selectedBlock.usedIn.length > 0 ? selectedBlock.usedIn.join(', ') : 'Not allowed anywhere yet'],
                                ['Reads from', selectedBlock.dependsOn.length > 0 ? selectedBlock.dependsOn.join(', ') : 'Only its own fields'],
                            ]}
                        >
                            <FieldTable fields={selectedBlock.fields} onSelectGroup={(name) => setSelection({ kind: 'group', id: name })} />
                        </Detail>
                    )}

                    {selectedGroup && (
                        <Detail
                            icon={<Layers className="size-5" />}
                            title={selectedGroup.name}
                            handle={`FieldGroup::define('${selectedGroup.name}')`}
                            definedAt={selectedGroup.definedAt}
                            facts={[['Used in', selectedGroup.usedIn.length > 0 ? selectedGroup.usedIn.join(', ') : 'Not used yet']]}
                        >
                            <FieldTable fields={selectedGroup.fields} />
                        </Detail>
                    )}

                    {!selectedCollection && !selectedBlock && !selectedGroup && (
                        <Card><EmptyState icon={<FileCode2 className="size-5" />} title="Nothing selected" description="Pick a collection, block or field group." /></Card>
                    )}
                </div>
            </div>
        </>
    )
}

function fromHash(): Selection | null {
    const match = /^#(collection|block|group)-(.+)$/.exec(window.location.hash)

    return match ? { kind: match[1] as Selection['kind'], id: decodeURIComponent(match[2] ?? '') } : null
}

function RailGroup({ label, count, children }: { label: string; count: number; children: ReactNode }) {
    return (
        <div className="mb-4">
            <div className="flex items-center justify-between px-2.5 pb-1.5 text-[11px] font-medium text-fg-subtle">
                <span>{label}</span>
                <span className="tabular-nums">{count}</span>
            </div>
            <ul className="space-y-px">{children}</ul>
        </div>
    )
}

function RailItem({ active, onClick, icon, meta, children }: { active: boolean; onClick: () => void; icon: ReactNode; meta?: string; children: ReactNode }) {
    return (
        <li>
            <button
                type="button"
                onClick={onClick}
                className={cn(
                    'flex h-8 w-full items-center gap-2.5 rounded-[var(--radius-control)] px-2.5 text-left text-[13px] transition-colors [&>svg]:size-4 [&>svg]:shrink-0',
                    active ? 'bg-selected font-medium text-fg [&>svg]:text-accent' : 'text-fg-muted hover:bg-hover hover:text-fg [&>svg]:text-fg-subtle',
                )}
            >
                {icon}
                <span className="flex-1 truncate">{children}</span>
                {meta && <span className="text-[11.5px] tabular-nums text-fg-subtle">{meta}</span>}
            </button>
        </li>
    )
}

function Detail({ icon, title, handle, definedAt, facts, action, children }: { icon: ReactNode; title: string; handle: string; definedAt: Location | null; facts: Array<[string, ReactNode]>; action?: ReactNode; children: ReactNode }) {
    return (
        <div className="space-y-4">
            <Card>
                <div className="flex flex-wrap items-start justify-between gap-4 p-5">
                    <div className="flex items-center gap-3.5">
                        <span className="flex size-10 items-center justify-center rounded-[10px] border border-line bg-sunken text-fg-muted">{icon}</span>
                        <div>
                            <h2 className="text-[17px] font-semibold">{title}</h2>
                            <div className="mt-0.5 flex flex-wrap items-center gap-2 text-[12px] text-fg-subtle">
                                <code>{handle}</code>
                                {definedAt && <SourceLink at={definedAt} />}
                            </div>
                        </div>
                    </div>
                    {action}
                </div>
                <dl className="grid grid-cols-1 gap-x-6 gap-y-3 border-t border-line px-5 py-4 text-[13px] sm:grid-cols-2">
                    {facts.map(([label, value]) => (
                        <div key={label} className="min-w-0">
                            <dt className="text-[11.5px] text-fg-subtle">{label}</dt>
                            <dd className="mt-0.5 truncate font-medium">{value}</dd>
                        </div>
                    ))}
                </dl>
            </Card>
            {children}
        </div>
    )
}

function SourceLink({ at }: { at: Location }) {
    const text = `${at.file}:${at.line}`

    return (
        <Tooltip content="Copy file and line">
            <button type="button" onClick={() => copy(text, 'Location copied')} className="inline-flex items-center gap-1 rounded px-1 font-mono text-[11.5px] text-fg-subtle hover:bg-hover hover:text-fg">
                <FileCode2 className="size-3" />
                {text}
            </button>
        </Tooltip>
    )
}

function FieldTable({ fields, showSource = false, showPlacement = false, onSelectBlock, onSelectGroup }: { fields: FieldInfo[]; showSource?: boolean; showPlacement?: boolean; onSelectBlock?: (handle: string) => void; onSelectGroup?: (name: string) => void }) {
    const rows = useMemo(() => flatten(fields), [fields])

    return (
        <Card className="overflow-hidden">
            <div className="flex items-center justify-between border-b border-line px-4 py-2.5">
                <h3 className="text-[12.5px] font-semibold">Fields</h3>
                <span className="text-[12px] text-fg-subtle">{fields.length} top level</span>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full min-w-[760px] text-[13px]">
                    <thead>
                        <tr className="border-b border-line bg-sunken/60 text-left text-[11.5px] font-medium text-fg-subtle">
                            <th className="py-2 pl-4 font-medium">Field</th>
                            <th className="py-2 pr-4 font-medium">Type</th>
                            <th className="py-2 pr-4 font-medium">Rules</th>
                            <th className="py-2 pr-4 font-medium">{showPlacement ? 'In the editor' : 'Shown'}</th>
                            <th className="w-20 py-2 pr-3" />
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map(({ field, depth, path }) => (
                            <tr key={path} className="group border-b border-line align-top last:border-b-0 hover:bg-hover/50">
                                <td className="py-2.5 pl-4">
                                    <div className="flex items-start gap-1.5" style={{ paddingLeft: depth * 18 }}>
                                        {depth > 0 && <CornerDownRight className="mt-0.5 size-3.5 shrink-0 text-fg-subtle" />}
                                        <div className="min-w-0">
                                            <div className="font-medium">{field.label}</div>
                                            <code className="text-[11.5px] text-fg-subtle">{field.name}</code>
                                            {field.help && <p className="mt-1 max-w-sm text-[12px] leading-snug text-fg-subtle">{field.help}</p>}
                                        </div>
                                    </div>
                                </td>
                                <td className="py-2.5 pr-4"><TypeBadge type={field.type} /></td>
                                <td className="py-2.5 pr-4">
                                    <div className="flex max-w-xs flex-wrap gap-1">
                                        <Rules field={field} onSelectBlock={onSelectBlock} onSelectGroup={onSelectGroup} />
                                    </div>
                                </td>
                                <td className="py-2.5 pr-4 text-[12.5px]">
                                    <div className="flex flex-col gap-1">
                                        {showPlacement && depth === 0 && (
                                            <span className="inline-flex items-center gap-1.5 text-fg-muted">
                                                <PanelRight className="size-3.5 text-fg-subtle" />
                                                {placement(field)}
                                            </span>
                                        )}
                                        {field.condition && (
                                            <span className="inline-flex items-center gap-1.5 text-fg-muted">
                                                <Eye className="size-3.5 text-fg-subtle" />
                                                when <code className="text-[11.5px]">{field.condition.field}</code> is {field.condition.values.map(String).join(' or ')}
                                            </span>
                                        )}
                                    </div>
                                </td>
                                <td className="py-2.5 pr-3 text-right">
                                    <div className="flex justify-end gap-0.5 opacity-0 transition-opacity group-hover:opacity-100 focus-within:opacity-100">
                                        {showSource && field.definedAt && (
                                            <Tooltip content={`${field.definedAt.file}:${field.definedAt.line}`}>
                                                <button type="button" onClick={() => copy(`${field.definedAt!.file}:${field.definedAt!.line}`, 'Location copied')} className="inline-flex size-7 items-center justify-center rounded-md text-fg-subtle hover:bg-selected hover:text-fg" aria-label="Copy location">
                                                    <FileCode2 className="size-3.5" />
                                                </button>
                                            </Tooltip>
                                        )}
                                        <Tooltip content="Copy as code">
                                            <button type="button" onClick={() => copy(toCode(field), 'Copied as code')} className="inline-flex size-7 items-center justify-center rounded-md text-fg-subtle hover:bg-selected hover:text-fg" aria-label="Copy as code">
                                                <Copy className="size-3.5" />
                                            </button>
                                        </Tooltip>
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="flex items-center gap-2 border-t border-line bg-sunken/40 px-4 py-2.5 text-[12px] text-fg-subtle">
                <Info className="size-3.5" />
                {showPlacement ? 'Fields in the inspector sit beside the writing area. Short settings go there unless the schema says otherwise.' : 'These fields appear together wherever this is used.'}
            </div>
        </Card>
    )
}

function flatten(fields: FieldInfo[], depth = 0, prefix = ''): Array<{ field: FieldInfo; depth: number; path: string }> {
    return fields.flatMap((field) => {
        const path = prefix === '' ? field.name : `${prefix}.${field.name}`

        return [{ field, depth, path }, ...flatten(field.fields, depth + 1, path)]
    })
}

const TYPE_TONES: Record<string, 'neutral' | 'accent' | 'info' | 'success' | 'warning'> = {
    richText: 'info',
    blocks: 'accent',
    repeater: 'accent',
    group: 'accent',
    media: 'success',
    relation: 'warning',
    seo: 'neutral',
}

function TypeBadge({ type }: { type: string }) {
    return <Badge tone={TYPE_TONES[type] ?? 'neutral'} className="font-mono !text-[11px]">{type}</Badge>
}

function Chip({ children, onClick }: { children: ReactNode; onClick?: () => void }) {
    const className = 'inline-flex h-5 items-center rounded border border-line bg-panel px-1.5 text-[11.5px] text-fg-muted'

    return onClick ? (
        <button type="button" onClick={onClick} className={cn(className, 'hover:border-accent hover:text-accent')}>{children}</button>
    ) : (
        <span className={className}>{children}</span>
    )
}

function Rules({ field, onSelectBlock, onSelectGroup }: { field: FieldInfo; onSelectBlock?: (handle: string) => void; onSelectGroup?: (name: string) => void }) {
    const chips: ReactNode[] = []

    if (field.required) chips.push(<Chip key="req">required</Chip>)
    if (field.maxLength) chips.push(<Chip key="max">max {field.maxLength}</Chip>)
    if (field.slugSource) chips.push(<Chip key="from">from {field.slugSource}</Chip>)
    if (field.options.length > 0) chips.push(<Chip key="opt">{field.options.map((o) => o || '(none)').join(' · ')}</Chip>)
    if (field.default !== null && field.default !== '') chips.push(<Chip key="def">default {field.default}</Chip>)
    if (field.relatedCollection) chips.push(<Chip key="rel">→ {field.relatedCollection}{field.many ? ', many' : ''}</Chip>)
    if (field.requireAlt) chips.push(<Chip key="alt">alt text required</Chip>)
    if (field.preset) chips.push(<Chip key="pre">{field.preset} crop</Chip>)
    if (field.min !== null || field.max !== null) chips.push(<Chip key="rows">{field.min ?? 0}–{field.max ?? '∞'} rows</Chip>)
    if (field.uses) chips.push(<Chip key="uses" onClick={onSelectGroup ? () => onSelectGroup(field.uses!) : undefined}>uses {field.uses}</Chip>)
    field.lint.forEach((rule) => chips.push(<Chip key={`lint-${rule}`}>{rule}</Chip>))
    field.blocks.forEach((handle) => chips.push(<Chip key={`b-${handle}`} onClick={onSelectBlock ? () => onSelectBlock(handle) : undefined}>{handle}</Chip>))

    return chips.length > 0 ? <>{chips}</> : <span className="text-[12px] text-fg-subtle">—</span>
}

function placement(field: FieldInfo): string {
    if (field.type === 'seo') return 'SEO tab'
    if (['title', 'status', 'published_at'].includes(field.name)) return field.name === 'title' ? 'Title' : 'Publishing'
    if (field.sidebar) return 'Inspector'

    return field.tab ? `${field.tab} tab` : 'Content tab'
}

/**
 * The field as schema code. Reconstructed from the field's description, so
 * it is the canonical form of the definition rather than a copy of the
 * source text, which is what you want when asking for a change to it.
 */
function toCode(field: FieldInfo, indent = ''): string {
    const q = (value: string) => `'${value.replace(/'/g, "\\'")}'`
    const parts: string[] = [field.type === 'seo' ? 'Field::seo()' : `Field::${field.type}(${q(field.name)})`]

    if (field.required) parts.push('->required()')
    if (field.maxLength) parts.push(`->maxLength(${field.maxLength})`)
    if (field.slugSource) parts.push(`->from(${q(field.slugSource)})`)
    if (field.options.length > 0) parts.push(`->options([${field.options.map(q).join(', ')}])`)
    if (field.default !== null) parts.push(`->default(${q(field.default)})`)
    if (field.relatedCollection) parts.push(`->to(${q(field.relatedCollection)})${field.many ? '->many()' : ''}`)
    if (field.requireAlt) parts.push('->requireAlt()')
    if (field.preset) parts.push(`->preset(${q(field.preset)})`)
    if (field.lint.length > 0) parts.push(`->lint([${field.lint.map(q).join(', ')}])`)
    if (field.min !== null) parts.push(`->min(${field.min})`)
    if (field.max !== null) parts.push(`->max(${field.max})`)
    if (field.tab) parts.push(`->tab(${q(field.tab)})`)
    if (field.condition) {
        const values = field.condition.values.map((v) => (typeof v === 'string' ? q(v) : String(v)))
        parts.push(`->showWhen(${q(field.condition.field)}, ${values.length === 1 ? values[0] : `[${values.join(', ')}]`})`)
    }
    if (field.help) parts.push(`->help(${q(field.help)})`)
    if (field.uses) parts.push(`->uses(${q(field.uses)})`)
    else if (field.fields.length > 0) {
        const inner = field.fields.map((sub) => `${indent}    ${toCode(sub, `${indent}    `)},`).join('\n')
        parts.push(`->fields([\n${inner}\n${indent}])`)
    }

    return parts.join('')
}

function copy(text: string, message: string): void {
    void navigator.clipboard
        .writeText(text)
        .then(() => toast(message, { tone: 'success' }))
        .catch(() => toast('Could not copy to the clipboard', { tone: 'error' }))
}

