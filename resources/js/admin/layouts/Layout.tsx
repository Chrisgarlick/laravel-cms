import { Link, router, usePage } from '@inertiajs/react'
import { ChevronRight, ExternalLink, LayoutDashboard, LogOut, Menu as MenuIcon, Monitor, Moon, PanelLeft, Search, Sun } from 'lucide-react'
import { useEffect, useState, type ReactNode } from 'react'
import { CommandPalette } from '@/components/CommandPalette'
import { Button, Kbd, Menu, MenuContent, MenuItem, MenuLabel, MenuRadioGroup, MenuRadioItem, MenuSeparator, MenuTrigger, Toaster, Tooltip, TooltipProvider, cn, toast } from '@/components/ui'
import { useTheme, type ThemeChoice } from '@/hooks/useTheme'
import type { SharedProps } from '@/types'
import { collectionIcon, navGroups, type NavItem } from './nav'

const COLLAPSED_KEY = 'cg-cms:sidebar-collapsed'

/**
 * The admin shell: grouped sidebar, top bar, command palette, toasts.
 *
 * The navigation is built from the collection registry, so a collection
 * added to `app/Cms/schema.php` appears here with no other change. Flash
 * messages from the server become toasts, so every screen reports success
 * and failure the same way.
 */
export default function Layout({ children }: { children: ReactNode }) {
    const { cms, auth, flash, nav } = usePage<SharedProps>().props
    const url = usePage().url
    const [paletteOpen, setPaletteOpen] = useState(false)
    const [mobileOpen, setMobileOpen] = useState(false)
    const [collapsed, setCollapsed] = useState(() => {
        try {
            return localStorage.getItem(COLLAPSED_KEY) === '1'
        } catch {
            return false
        }
    })

    useEffect(() => {
        try {
            localStorage.setItem(COLLAPSED_KEY, collapsed ? '1' : '0')
        } catch {
            // Not remembered; harmless.
        }
    }, [collapsed])

    // ⌘K / Ctrl+K anywhere, including inside inputs.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault()
                setPaletteOpen((open) => !open)
            }
        }
        window.addEventListener('keydown', onKey)

        return () => window.removeEventListener('keydown', onKey)
    }, [])

    useEffect(() => {
        if (flash.success) toast(flash.success)
        if (flash.error) toast(flash.error, { tone: 'error' })
    }, [flash])

    useEffect(() => setMobileOpen(false), [url])

    const path = url.split('?')[0] ?? url
    // The editor runs edge to edge (its own sticky bar and inspector); every other screen sits in a column.
    const fullBleed = /\/(new|edit)$/.test(path)
    const isActive = (href: string) => (href === cms.prefix ? path === href : path === href || path.startsWith(`${href}/`))

    const content: NavItem[] = cms.collections.map((collection) => {
        const Icon = collectionIcon(collection.handle)

        return {
            href: `${cms.prefix}/${collection.handle}`,
            label: collection.label,
            icon: <Icon />,
            count: nav?.collections[collection.handle]?.total,
        }
    })

    const sidebar = (compact: boolean) => (
        <div className="flex h-full flex-col">
            <div className={cn('flex h-14 shrink-0 items-center gap-2.5', compact ? 'justify-center px-2' : 'px-4')}>
                <Link href={cms.prefix} className="flex min-w-0 items-center gap-2.5">
                    <span className="brand-mark flex size-8 shrink-0 items-center justify-center rounded-[9px] font-display text-[15px] font-semibold text-on-accent">
                        {cms.site.charAt(0)}
                    </span>
                    {!compact && (
                        <span className="min-w-0 leading-tight">
                            <span className="block truncate font-display text-[15px] font-semibold tracking-[-0.01em]">{cms.site}</span>
                            <span className="block text-[11px] text-fg-subtle">Content studio</span>
                        </span>
                    )}
                </Link>
            </div>

            <nav className={'kit-sidebar flex-1 overflow-y-auto px-2 pt-1 pb-4'} aria-label="Admin">
                <ul>
                    <SideLink item={{ href: cms.prefix, label: 'Dashboard', icon: <LayoutDashboard /> }} active={isActive(cms.prefix)} compact={compact} />
                </ul>
                <SideGroup label="Content" compact={compact}>
                    {content.map((item) => <SideLink key={item.href} item={item} active={isActive(item.href)} compact={compact} />)}
                </SideGroup>
                {navGroups(cms, nav).map((group) => (
                    <SideGroup key={group.label} label={group.label} compact={compact}>
                        {group.items.map((item) => <SideLink key={item.href} item={item} active={isActive(item.href)} compact={compact} />)}
                    </SideGroup>
                ))}
            </nav>

            <div className="shrink-0 border-t border-line p-2">
                <UserMenu compact={compact} />
            </div>
        </div>
    )

    return (
        <TooltipProvider delayDuration={300}>
            <div className="flex min-h-dvh bg-canvas text-fg">
                <aside
                    className={cn(
                        'sticky top-0 hidden h-dvh shrink-0 border-r border-line bg-panel transition-[width] duration-200 ease-[var(--ease-snap)] md:block',
                        collapsed ? 'w-[60px]' : 'w-[240px]',
                    )}
                >
                    {sidebar(collapsed)}
                </aside>

                {mobileOpen && (
                    <div className="fixed inset-0 z-40 md:hidden">
                        <div className="absolute inset-0 bg-black/40 animate-in" onClick={() => setMobileOpen(false)} />
                        <aside className="absolute top-0 bottom-0 left-0 w-[260px] border-r border-line bg-panel shadow-lg animate-in">{sidebar(false)}</aside>
                    </div>
                )}

                <div className="flex min-w-0 flex-1 flex-col">
                    <header className="kit-topbar h-14 shrink-0 gap-2 px-3 md:px-5" data-sticky="">
                        <span className="contents md:hidden">
                            <Button variant="ghost" size="icon" onClick={() => setMobileOpen(true)} aria-label="Open navigation">
                                <MenuIcon className="size-4" />
                            </Button>
                        </span>
                        <span className="hidden md:contents">
                            <Tooltip content={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}>
                                <Button variant="ghost" size="icon" onClick={() => setCollapsed((value) => !value)} aria-label="Toggle sidebar">
                                    <PanelLeft className="size-4" />
                                </Button>
                            </Tooltip>
                        </span>

                        <Breadcrumb path={path} />

                        <div className="kit-topbar-actions">
                            <button
                                type="button"
                                onClick={() => setPaletteOpen(true)}
                                className="kit-button justify-start font-normal text-fg-subtle sm:w-56"
                                data-size="sm"
                            >
                                <Search className="size-3.5" />
                                <span className="hidden flex-1 text-left sm:block">Search…</span>
                                <span className="hidden gap-0.5 sm:flex"><Kbd>⌘</Kbd><Kbd>K</Kbd></span>
                            </button>
                            <Tooltip content="View site">
                                <a href="/" target="_blank" rel="noreferrer" className="kit-button" data-variant="ghost" data-icon="" aria-label="View site">
                                    <ExternalLink className="size-4" />
                                </a>
                            </Tooltip>
                            <ThemeMenu />
                        </div>
                    </header>

                    <main className="relative w-full flex-1 animate-in px-4 py-6 md:px-8 md:py-8">
                        <div className="canvas-glow" aria-hidden />
                        <div className={cn('relative', !fullBleed && 'mx-auto w-full max-w-[1280px]')}>{children}</div>
                    </main>
                </div>

                <CommandPalette open={paletteOpen} onOpenChange={setPaletteOpen} />
                <Toaster />
            </div>
        </TooltipProvider>
    )

    function UserMenu({ compact }: { compact: boolean }) {
        if (auth.user === null) return null

        const initials = auth.user.name.split(/\s+/).map((part) => part.charAt(0)).join('').slice(0, 2).toUpperCase()

        return (
            <Menu>
                <MenuTrigger asChild>
                    <button type="button" className={cn('flex w-full items-center gap-2.5 rounded-[var(--radius-control)] p-1.5 text-left hover:bg-hover', compact && 'justify-center')}>
                        <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-sunken text-[11px] font-semibold text-fg-muted ring-1 ring-line">{initials}</span>
                        {!compact && (
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-[13px] font-medium">{auth.user.name}</span>
                                <span className="block truncate text-[11.5px] text-fg-subtle">{auth.user.email}</span>
                            </span>
                        )}
                    </button>
                </MenuTrigger>
                <MenuContent side="top" align="start" className="w-56">
                    <MenuLabel>{auth.user.email}</MenuLabel>
                    <MenuItem icon={<LogOut />} onSelect={() => router.post(`${cms.prefix}/signout`)}>Sign out</MenuItem>
                </MenuContent>
            </Menu>
        )
    }

    function Breadcrumb({ path }: { path: string }) {
        const rest = path.slice(cms.prefix.length).split('/').filter(Boolean)
        const crumbs: Array<{ label: string; href?: string }> = []

        if (rest.length === 0) {
            crumbs.push({ label: 'Dashboard' })
        } else {
            const first = rest[0] ?? ''
            const collection = cms.collections.find((c) => c.handle === first)
            const screen = navGroups(cms, null).flatMap((g) => g.items).find((item) => item.href === `${cms.prefix}/${first}`)
            const label = collection?.label ?? screen?.label ?? first.charAt(0).toUpperCase() + first.slice(1)

            crumbs.push({ label, href: rest.length > 1 ? `${cms.prefix}/${first}` : undefined })
            if (rest[1] === 'new') crumbs.push({ label: 'New' })
            else if (rest[2] === 'edit') crumbs.push({ label: 'Edit' })
        }

        return (
            <ol className="flex min-w-0 items-center gap-1.5 text-[13px]">
                {crumbs.map((crumb, index) => (
                    <li key={index} className="flex min-w-0 items-center gap-1.5">
                        {index > 0 && <ChevronRight className="size-3.5 shrink-0 text-fg-subtle" />}
                        {crumb.href ? (
                            <Link href={crumb.href} className="truncate text-fg-muted hover:text-fg">{crumb.label}</Link>
                        ) : (
                            <span className="truncate font-medium text-fg">{crumb.label}</span>
                        )}
                    </li>
                ))}
            </ol>
        )
    }
}

function SideGroup({ label, compact, children }: { label: string; compact: boolean; children: ReactNode }) {
    return (
        <>
            {compact ? <hr className="mx-3 my-3 border-line" /> : <p className="kit-sidebar-label">{label}</p>}
            <ul>{children}</ul>
        </>
    )
}

function SideLink({ item, active, compact }: { item: NavItem; active: boolean; compact: boolean }) {
    const link = (
        <Link href={item.href} aria-current={active ? 'page' : undefined} className={cn('relative', compact && 'justify-center !px-0')}>
            {item.icon}
            {!compact && <span className="flex-1 truncate">{item.label}</span>}
            {!compact && item.count !== undefined && item.count > 0 && <span className="kit-sidebar-meta">{item.count}</span>}
            {item.attention && <span className={cn('kit-dot text-accent', compact && 'absolute top-1.5 right-2')} aria-label="Needs attention" />}
        </Link>
    )

    return <li>{compact ? <Tooltip content={item.label} side="right">{link}</Tooltip> : link}</li>
}

function ThemeMenu() {
    const [theme, setTheme] = useTheme()
    const Icon = theme === 'light' ? Sun : theme === 'dark' ? Moon : Monitor

    return (
        <Menu>
            <MenuTrigger asChild>
                <Button variant="ghost" size="icon" aria-label="Theme">
                    <Icon className="size-4" />
                </Button>
            </MenuTrigger>
            <MenuContent className="w-40">
                <MenuRadioGroup value={theme} onValueChange={(value) => setTheme(value as ThemeChoice)}>
                    <MenuRadioItem value="light" icon={<Sun />}>Light</MenuRadioItem>
                    <MenuRadioItem value="dark" icon={<Moon />}>Dark</MenuRadioItem>
                    <MenuRadioItem value="system" icon={<Monitor />}>System</MenuRadioItem>
                </MenuRadioGroup>
                <MenuSeparator />
                <MenuLabel>⌘K for everything else</MenuLabel>
            </MenuContent>
        </Menu>
    )
}
