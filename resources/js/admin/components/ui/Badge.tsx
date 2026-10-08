import type { ReactNode } from 'react'
import { cn } from './cn'

export type Tone = 'neutral' | 'accent' | 'success' | 'warning' | 'danger' | 'info'

const tones: Record<Tone, string> = {
    neutral: 'bg-sunken text-fg-muted',
    accent: 'bg-accent-soft text-accent',
    success: 'bg-success-soft text-success',
    warning: 'bg-warning-soft text-warning',
    danger: 'bg-danger-soft text-danger',
    info: 'bg-info-soft text-info',
}

/** A small status label. `dot` adds the coloured dot used for entry status. */
export function Badge({ tone = 'neutral', dot = false, className, children }: { tone?: Tone; dot?: boolean; className?: string; children: ReactNode }) {
    return (
        <span className={cn('inline-flex h-5 items-center gap-1.5 rounded-full px-2 text-[11.5px] font-medium', tones[tone], className)}>
            {dot && <span className="size-1.5 rounded-full bg-current" aria-hidden />}
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
