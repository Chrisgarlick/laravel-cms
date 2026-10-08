import { useCallback, useEffect, useSyncExternalStore } from 'react'

export type ThemeChoice = 'light' | 'dark' | 'system'

const KEY = 'cg-cms:theme'
const listeners = new Set<() => void>()

function read(): ThemeChoice {
    try {
        const value = localStorage.getItem(KEY)

        return value === 'light' || value === 'dark' ? value : 'system'
    } catch {
        return 'system'
    }
}

/**
 * Put the choice on <html>. "System" removes the attribute, which lets the
 * stylesheet's prefers-color-scheme block decide. The root view runs the same
 * logic inline before first paint, so there is no flash of the wrong theme.
 */
function apply(choice: ThemeChoice): void {
    if (choice === 'system') {
        delete document.documentElement.dataset.theme
    } else {
        document.documentElement.dataset.theme = choice
    }
}

/** The admin's light/dark/system preference, per browser. */
export function useTheme(): [ThemeChoice, (choice: ThemeChoice) => void] {
    const theme = useSyncExternalStore(
        (notify) => {
            listeners.add(notify)

            return () => listeners.delete(notify)
        },
        read,
        () => 'system' as ThemeChoice,
    )

    useEffect(() => apply(theme), [theme])

    const set = useCallback((choice: ThemeChoice) => {
        try {
            if (choice === 'system') localStorage.removeItem(KEY)
            else localStorage.setItem(KEY, choice)
        } catch {
            // Private mode: the choice lasts for this page only.
        }
        apply(choice)
        listeners.forEach((notify) => notify())
    }, [])

    return [theme, set]
}
