import type { ReactNode } from 'react'

/** The title row at the top of every screen: heading, optional description, actions. */
export function PageHeader({ title, description, actions }: { title: ReactNode; description?: ReactNode; actions?: ReactNode }) {
    return (
        <header className="mb-7 flex flex-wrap items-end justify-between gap-4">
            <div className="min-w-0">
                <h1 className="text-[28px] leading-[1.1]">{title}</h1>
                {description && <p className="mt-1.5 text-[13.5px] text-fg-muted">{description}</p>}
            </div>
            {actions && <div className="flex items-center gap-2">{actions}</div>}
        </header>
    )
}
