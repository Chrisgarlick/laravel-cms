import { router } from '@inertiajs/react'
import { useState } from 'react'

export interface Revision {
    id: number
    createdAt: string | null
    title: string
    status: string
}

interface Props {
    revisions: Revision[]
    restoreUrl: (revision: Revision) => string
}

/**
 * Revision history, in a drawer.
 *
 * A drawer rather than a column, because history is consulted rarely and the
 * editor needs the width. It stays out of the way until asked for.
 *
 * Restoring asks first. It is an undoable action in the sense that the current
 * state is itself snapshotted on the way past, but "undoable if you know that"
 * is not the same as "safe", and the person clicking it is usually not certain
 * which version they are picking.
 */
export default function RevisionDrawer({ revisions, restoreUrl }: Props) {
    const [open, setOpen] = useState(false)

    return (
        <>
            <button type="button" className="button-quiet" onClick={() => setOpen(true)}>
                History ({revisions.length})
            </button>

            {open && (
                <div className="drawer" role="dialog" aria-label="Revision history">
                    <header className="drawer-head">
                        <strong>History</strong>
                        <button type="button" className="button-quiet" onClick={() => setOpen(false)}>
                            Close
                        </button>
                    </header>

                    {revisions.length === 0 ? (
                        <p className="panel-help">No revisions yet.</p>
                    ) : (
                        <ol className="drawer-list">
                            {revisions.map((revision) => (
                                <li key={revision.id}>
                                    <div>
                                        <p className="drawer-title">{revision.title || 'Untitled'}</p>
                                        <p className="panel-help">
                                            {formatted(revision.createdAt)} &middot; {revision.status}
                                        </p>
                                    </div>

                                    <button
                                        type="button"
                                        className="button-quiet"
                                        onClick={() => {
                                            if (
                                                window.confirm(
                                                    'Restore this version? The current one is kept in the history.',
                                                )
                                            ) {
                                                router.post(restoreUrl(revision))
                                            }
                                        }}
                                    >
                                        Restore
                                    </button>
                                </li>
                            ))}
                        </ol>
                    )}
                </div>
            )}
        </>
    )
}

function formatted(iso: string | null): string {
    if (iso === null) {
        return 'unknown'
    }

    const date = new Date(iso)

    return Number.isNaN(date.getTime())
        ? 'unknown'
        : date.toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' })
}
