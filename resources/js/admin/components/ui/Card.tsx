import type { ReactNode } from 'react'
import { cn } from './cn'

/** A surface. `interactive` adds the hover lift used for linkable cards. */
export function Card({ className, interactive = false, children }: { className?: string; interactive?: boolean; children: ReactNode }) {
    return (
        <div
            className={cn(
                'rounded-[var(--radius-card)] border border-line bg-panel shadow-sm',
                interactive && 'transition-[border-color,box-shadow] duration-200 hover:border-line-strong hover:shadow-md',
                className,
            )}
        >
            {children}
        </div>
    )
}

export function CardHeader({ title, action, description }: { title: ReactNode; action?: ReactNode; description?: ReactNode }) {
    return (
        <div className="flex items-start justify-between gap-4 px-4 pt-3.5 pb-2">
            <div>
                <h2 className="text-[13px] font-semibold text-fg">{title}</h2>
                {description && <p className="mt-0.5 text-[12.5px] text-fg-muted">{description}</p>}
            </div>
            {action}
        </div>
    )
}
