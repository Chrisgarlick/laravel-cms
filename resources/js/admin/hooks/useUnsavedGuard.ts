import { router } from '@inertiajs/react'
import { useEffect } from 'react'

/**
 * Warns before leaving a form with unsaved changes.
 *
 * Two exits to cover, and they are completely separate mechanisms. Closing the
 * tab or hitting the back button is the browser's `beforeunload`, which can
 * only show the browser's own wording. Clicking a link inside the admin never
 * touches that event at all, because Inertia navigates without a page load, so
 * it needs the router's own hook.
 *
 * Covering only the first is the common mistake, and it is the less likely of
 * the two: the way an editor actually loses work is by clicking "Dashboard"
 * with a half-written article open.
 */
export function useUnsavedGuard(dirty: boolean): void {
    useEffect(() => {
        if (!dirty) {
            return
        }

        const warn = (event: BeforeUnloadEvent): void => {
            event.preventDefault()
            event.returnValue = ''
        }

        window.addEventListener('beforeunload', warn)

        const stop = router.on('before', (event) => {
            // Autosave and the lint preview are plain fetch calls, so they do
            // not pass through here. Saving, publishing and deleting do, and
            // they are not leaving: only a GET visit to another page is.
            const visit = event.detail.visit

            if (visit.method !== 'get' || visit.only.length > 0) {
                return
            }

            if (!window.confirm('You have unsaved changes. Leave without saving?')) {
                event.preventDefault()
            }
        })

        return () => {
            window.removeEventListener('beforeunload', warn)
            stop()
        }
    }, [dirty])
}
