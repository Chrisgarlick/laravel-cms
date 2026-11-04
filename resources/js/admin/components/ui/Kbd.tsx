import type { ReactNode } from 'react'

/** A keyboard hint, e.g. <Kbd>⌘</Kbd><Kbd>K</Kbd>: kit's kbd. */
export function Kbd({ children }: { children: ReactNode }) {
    return <kbd className="kit-kbd">{children}</kbd>
}
