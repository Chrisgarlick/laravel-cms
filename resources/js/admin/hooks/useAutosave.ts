import { useEffect, useRef, useState } from 'react'

interface Options {
    /** Where to post. Null disables autosave, which is the case for a new entry. */
    url: string | null
    values: Record<string, unknown>
    enabled: boolean
    intervalMs?: number
}

export interface AutosaveState {
    savedAt: string | null
    saving: boolean
    failed: boolean
}

/**
 * Saves the editor's work in progress to a draft revision.
 *
 * On a timer rather than on every change, because the point is recovering
 * from a closed laptop rather than reflecting every keystroke. Fifteen seconds
 * is what section 5.12 asks for.
 *
 * Three things it deliberately does not do:
 *
 *   - It does not save the entry. Autosaving over a published row every
 *     fifteen seconds would put half-finished edits on the live site, and
 *     there is no undo for that. The server writes a revision instead.
 *   - It does not fire when nothing has changed. A tab left open overnight
 *     should not write 2,000 identical revisions.
 *   - It does not retry. A failed autosave surfaces in the UI and the next
 *     tick tries again anyway; stacking retries on a server that is already
 *     unhappy helps nobody.
 */
export function useAutosave({ url, values, enabled, intervalMs = 15_000 }: Options): AutosaveState {
    const [state, setState] = useState<AutosaveState>({
        savedAt: null,
        saving: false,
        failed: false,
    })

    // Refs rather than dependencies, so changing a character does not restart
    // the timer. An interval that resets on every keystroke never fires while
    // somebody is typing, which is exactly when it is wanted.
    const latest = useRef(values)
    const lastSaved = useRef<string | null>(null)

    latest.current = values

    useEffect(() => {
        if (url === null || !enabled) {
            return
        }

        const timer = setInterval(() => {
            const serialised = JSON.stringify(latest.current)

            if (serialised === lastSaved.current) {
                return
            }

            setState((previous) => ({ ...previous, saving: true }))

            void fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ values: latest.current }),
            })
                .then(async (response) => {
                    if (!response.ok) {
                        throw new Error(String(response.status))
                    }

                    const body = (await response.json()) as { savedAt: string }

                    lastSaved.current = serialised
                    setState({ savedAt: body.savedAt, saving: false, failed: false })
                })
                .catch(() => setState((previous) => ({ ...previous, saving: false, failed: true })))
        }, intervalMs)

        return () => clearInterval(timer)
    }, [url, enabled, intervalMs])

    return state
}

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}
