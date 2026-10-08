import type { ReactNode } from 'react'

/** Nothing here yet, and the one thing to do about it. */
export function EmptyState({ icon, title, description, action }: { icon?: ReactNode; title: string; description?: string; action?: ReactNode }) {
    return (
        <div className="flex flex-col items-center justify-center px-6 py-16 text-center">
            {icon && <div className="mb-4 flex size-11 items-center justify-center rounded-xl border border-line bg-panel text-fg-muted shadow-sm">{icon}</div>}
            <h3 className="text-[14px] font-semibold">{title}</h3>
            {description && <p className="mt-1 max-w-sm text-[13px] text-fg-muted">{description}</p>}
            {action && <div className="mt-5">{action}</div>}
        </div>
    )
}
