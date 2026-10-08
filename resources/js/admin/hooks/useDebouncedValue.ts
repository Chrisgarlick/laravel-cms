import { useEffect, useState } from 'react'

/**
 * A value that settles after typing stops.
 *
 * Used by the lint and SEO panel, which posts the form to the server to be
 * checked. Without this it would post on every keystroke: a request per
 * character, each one rendering a panel that is already out of date by the
 * time it arrives.
 */
export function useDebouncedValue<T>(value: T, delay: number): T {
    const [settled, setSettled] = useState(value)

    useEffect(() => {
        const timer = setTimeout(() => setSettled(value), delay)

        return () => clearTimeout(timer)
    }, [value, delay])

    return settled
}
