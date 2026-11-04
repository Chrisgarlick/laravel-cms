import type { ReactNode } from 'react'
import { cn } from './cn'

export type Tone = 'neutral' | 'accent' | 'success' | 'warning' | 'danger' | 'info'

const kitTone: Record<Tone, string | undefined> = {
    neutral: undefined,
    accent: 'accent',
    success: 'good',
    warning: 'warn',
    danger: 'bad',
    info: 'info',
}

/** A small status label: kit's badge. `dot` adds kit's dot, used for entry status. */
export function Badge({ tone = 'neutral', dot = false, className, children }: { tone?: Tone; dot?: boolean; className?: string; children: ReactNode }) {
    return (
        <span className={cn('kit-badge', className)} data-tone={kitTone[tone]}>
            {dot && <span className="kit-dot" aria-hidden />}
            {children}
        </span>
    )
}

/** The status badge every entry list and editor uses. */
export function StatusBadge({ status }: { status: string }) {
    const tone: Tone = status === 'published' ? 'success' : status === 'scheduled' ? 'warning' : 'neutral'

    return (
        <Badge tone={tone} dot>
            {status.charAt(0).toUpperCase() + status.slice(1)}
        </Badge>
    )
}
