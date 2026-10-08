import { router, usePage } from '@inertiajs/react'
import { Command } from 'cmdk'
import { Dialog as RadixDialog } from 'radix-ui'
import { FileText, Image, LayoutDashboard, Monitor, Moon, Plus, Search, Sun } from 'lucide-react'
import { useEffect, useState, type ReactNode } from 'react'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { useTheme } from '@/hooks/useTheme'
import { navItems } from '@/layouts/nav'
import type { SharedProps } from '@/types'
import { Kbd, StatusBadge } from './ui'

interface Results {
    entries: Array<{ id: number; title: string; collection: string; status: string; href: string }>
    media: Array<{ id: number; title: string; alt: string | null; href: string }>
}

/**
 * ⌘K: go anywhere, create anything, find any entry or image.
 *
 * Navigation and actions are filtered on the client by cmdk; entries and
 * media come from the server as you type, so the palette searches the whole
 * site rather than whatever happens to be loaded.
 */
export function CommandPalette({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    const { cms } = usePage<SharedProps>().props
    const [, setTheme] = useTheme()
    const [query, setQuery] = useState('')
    const [results, setResults] = useState<Results>({ entries: [], media: [] })
    const debounced = useDebouncedValue(query, 150)

    useEffect(() => {
        if (!open) return

        const controller = new AbortController()

        fetch(`${cms.prefix}/search?q=${encodeURIComponent(debounced)}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => (response.ok ? response.json() : { entries: [], media: [] }))
            .then(setResults)
            .catch(() => {})

        return () => controller.abort()
    }, [debounced, open, cms.prefix])

    useEffect(() => {
        if (!open) setQuery('')
    }, [open])

    const go = (href: string) => {
        onOpenChange(false)
        router.visit(href)
    }

    const run = (action: () => void) => {
        onOpenChange(false)
        action()
    }

    return (
        <RadixDialog.Root open={open} onOpenChange={onOpenChange}>
            <RadixDialog.Portal>
                <RadixDialog.Overlay className="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] animate-in" />
                <RadixDialog.Content
                    aria-describedby={undefined}
                    className="fixed top-[14vh] left-1/2 z-50 w-[min(620px,calc(100vw-32px))] -translate-x-1/2 overflow-hidden rounded-[var(--radius-dialog)] border border-line bg-elevated shadow-lg outline-none animate-pop"
                >
                    <RadixDialog.Title className="sr-only">Command palette</RadixDialog.Title>
                    <Command shouldFilter={false} loop className="flex flex-col">
                        <div className="flex items-center gap-3 border-b border-line px-4">
                            <Search className="size-4 shrink-0 text-fg-subtle" />
                            <Command.Input
                                value={query}
                                onValueChange={setQuery}
                                placeholder="Search entries, media, or jump to…"
                                className="h-12 flex-1 bg-transparent text-[15px] text-fg outline-none placeholder:text-fg-subtle"
                            />
                            <Kbd>esc</Kbd>
                        </div>

                        <Command.List className="max-h-[min(440px,60vh)] overflow-y-auto p-1.5 [&_[cmdk-group-heading]]:px-2.5 [&_[cmdk-group-heading]]:pt-2.5 [&_[cmdk-group-heading]]:pb-1 [&_[cmdk-group-heading]]:text-[11px] [&_[cmdk-group-heading]]:font-medium [&_[cmdk-group-heading]]:text-fg-subtle">
                            <Command.Empty className="px-3 py-10 text-center text-[13px] text-fg-muted">Nothing matches “{query}”.</Command.Empty>

                            {results.entries.length > 0 && (
                                <Command.Group heading={query === '' ? 'Recently edited' : 'Entries'}>
                                    {results.entries.map((entry) => (
                                        <Item key={`e${entry.id}`} value={`entry-${entry.id}`} icon={<FileText />} onSelect={() => go(entry.href)} meta={<StatusBadge status={entry.status} />}>
                                            <span className="truncate">{entry.title}</span>
                                            <span className="shrink-0 text-fg-subtle">{entry.collection}</span>
                                        </Item>
                                    ))}
                                </Command.Group>
                            )}

                            {results.media.length > 0 && (
                                <Command.Group heading="Media">
                                    {results.media.map((item) => (
                                        <Item key={`m${item.id}`} value={`media-${item.id}`} icon={<Image />} onSelect={() => go(item.href)}>
                                            <span className="truncate">{item.title}</span>
                                            {item.alt && <span className="truncate text-fg-subtle">{item.alt}</span>}
                                        </Item>
                                    ))}
                                </Command.Group>
                            )}

                            <Group heading="Create" query={query} items={cms.collections.map((c) => ({ key: `new-${c.handle}`, label: `New ${c.label.replace(/s$/, '').toLowerCase()}`, icon: <Plus />, onSelect: () => go(`${cms.prefix}/${c.handle}/new`) }))} />

                            <Group
                                heading="Go to"
                                query={query}
                                items={[
                                    { key: 'dash', label: 'Dashboard', icon: <LayoutDashboard />, onSelect: () => go(cms.prefix) },
                                    ...navItems(cms).map((item) => ({ key: item.href, label: item.label, icon: item.icon, onSelect: () => go(item.href) })),
                                ]}
                            />

                            <Group
                                heading="Theme"
                                query={query}
                                items={[
                                    { key: 'light', label: 'Light theme', icon: <Sun />, onSelect: () => run(() => setTheme('light')) },
                                    { key: 'dark', label: 'Dark theme', icon: <Moon />, onSelect: () => run(() => setTheme('dark')) },
                                    { key: 'system', label: 'System theme', icon: <Monitor />, onSelect: () => run(() => setTheme('system')) },
                                ]}
                            />
                        </Command.List>

                        <div className="flex items-center gap-4 border-t border-line bg-sunken px-4 py-2 text-[11.5px] text-fg-subtle">
                            <span className="flex items-center gap-1.5"><Kbd>↑</Kbd><Kbd>↓</Kbd> move</span>
                            <span className="flex items-center gap-1.5"><Kbd>↵</Kbd> open</span>
                        </div>
                    </Command>
                </RadixDialog.Content>
            </RadixDialog.Portal>
        </RadixDialog.Root>
    )
}

/** A client-filtered group. Hidden entirely when nothing in it matches. */
function Group({ heading, query, items }: { heading: string; query: string; items: Array<{ key: string; label: string; icon: ReactNode; onSelect: () => void }> }) {
    const needle = query.trim().toLowerCase()
    const shown = needle === '' ? items : items.filter((item) => item.label.toLowerCase().includes(needle))

    if (shown.length === 0) return null

    return (
        <Command.Group heading={heading}>
            {shown.map((item) => (
                <Item key={item.key} value={item.key} icon={item.icon} onSelect={item.onSelect}>
                    {item.label}
                </Item>
            ))}
        </Command.Group>
    )
}

function Item({ value, icon, meta, onSelect, children }: { value: string; icon: ReactNode; meta?: ReactNode; onSelect: () => void; children: ReactNode }) {
    return (
        <Command.Item
            value={value}
            onSelect={onSelect}
            className="flex h-9 cursor-default items-center gap-3 rounded-[6px] px-2.5 text-[13.5px] text-fg data-[selected=true]:bg-hover [&>svg]:size-4 [&>svg]:shrink-0 [&>svg]:text-fg-subtle"
        >
            {icon}
            <span className="flex min-w-0 flex-1 items-center gap-2">{children}</span>
            {meta}
        </Command.Item>
    )
}
