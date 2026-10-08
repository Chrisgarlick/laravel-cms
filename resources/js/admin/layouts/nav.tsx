import { ArrowRightLeft, BookOpen, Boxes, ListChecks, Briefcase, ChartColumn, FileText, Gauge, Image, Inbox, Layers, Library, Settings, Tag, Wrench, type LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import type { SharedProps } from '@/types'

/**
 * Icons for the collections this site defines. A collection not listed here
 * still appears in the sidebar, with the generic icon: the navigation is
 * built from the schema, the icons are only decoration.
 */
const collectionIcons: Record<string, LucideIcon> = {
    article: BookOpen,
    page: FileText,
    project: Briefcase,
    case_study: Layers,
    service: Gauge,
    tool: Wrench,
    resource: Library,
    tag: Tag,
    proof_metric: ChartColumn,
}

export function collectionIcon(handle: string): LucideIcon {
    return collectionIcons[handle] ?? FileText
}

export interface NavItem {
    href: string
    label: string
    icon: ReactNode
    count?: number
    attention?: boolean
}

/** The non-collection screens, grouped as the sidebar shows them. */
export function navGroups(cms: SharedProps['cms'], nav: SharedProps['nav']): Array<{ label: string; items: NavItem[] }> {
    return [
        {
            label: 'Library',
            items: [{ href: `${cms.prefix}/media`, label: 'Media', icon: <Image />, attention: (nav?.mediaMissingAlt ?? 0) > 0 }],
        },
        {
            label: 'Site',
            items: [
                { href: `${cms.prefix}/submissions`, label: 'Leads', icon: <Inbox />, count: nav?.leads, attention: nav?.leadsAttention },
                { href: `${cms.prefix}/seo`, label: 'SEO', icon: <ChartColumn />, count: nav?.seoBlocking || undefined, attention: (nav?.seoBlocking ?? 0) > 0 },
                { href: `${cms.prefix}/redirects`, label: 'Redirects', icon: <ArrowRightLeft /> },
                {
                    href: `${cms.prefix}/queue`,
                    label: 'Queue',
                    icon: <ListChecks />,
                    count: nav ? nav.queue.pending + nav.queue.failed || undefined : undefined,
                    attention: Boolean(nav && (nav.queue.failed > 0 || nav.queue.stalled)),
                },
                { href: `${cms.prefix}/schema`, label: 'Content model', icon: <Boxes /> },
                { href: `${cms.prefix}/settings`, label: 'Settings', icon: <Settings /> },
            ],
        },
    ]
}

/** Every non-collection screen, flat, for the command palette. */
export function navItems(cms: SharedProps['cms']): NavItem[] {
    return navGroups(cms, null).flatMap((group) => group.items)
}
