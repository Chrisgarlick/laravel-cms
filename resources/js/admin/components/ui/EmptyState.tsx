import type { ReactNode } from 'react'

/** Nothing here yet, and the one thing to do about it: kit's empty state. */
export function EmptyState({ icon, title, description, action }: { icon?: ReactNode; title: string; description?: string; action?: ReactNode }) {
    return (
        <div className="kit-empty py-16">
            {icon && <span className="kit-empty-icon" aria-hidden>{icon}</span>}
            <p className="kit-empty-title">{title}</p>
            {description && <p>{description}</p>}
            {action && <div className="kit-empty-actions">{action}</div>}
        </div>
    )
}
