import type { ReactNode } from 'react'
import { cn } from './cn'

/** A surface: kit's card. `interactive` adds the hover lift used for linkable cards. */
export function Card({ className, interactive = false, children }: { className?: string; interactive?: boolean; children: ReactNode }) {
    return (
        <div className={cn('kit-card', interactive && 'transition-[border-color,box-shadow] duration-200 hover:border-line-strong hover:shadow-md', className)}>
            {children}
        </div>
    )
}

export function CardHeader({ title, action, description }: { title: ReactNode; action?: ReactNode; description?: ReactNode }) {
    return (
        <header className="kit-card-header">
            <div>
                <h2>{title}</h2>
                {description && <p className="mt-0.5 text-fg-muted">{description}</p>}
            </div>
            {action}
        </header>
    )
}
