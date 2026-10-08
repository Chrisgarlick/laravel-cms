import type { ReactNode } from 'react'

/** A keyboard hint, e.g. <Kbd>⌘</Kbd><Kbd>K</Kbd>. */
export function Kbd({ children }: { children: ReactNode }) {
    return (
        <kbd className="inline-flex h-[18px] min-w-[18px] items-center justify-center rounded border border-line bg-sunken px-1 font-sans text-[10.5px] font-medium text-fg-subtle">
            {children}
        </kbd>
    )
}
