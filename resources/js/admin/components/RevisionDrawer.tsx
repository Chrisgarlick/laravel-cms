import { router } from '@inertiajs/react'
import { useState } from 'react'
import { Confirm, Dialog } from '@/components/ui'

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
 * kit's drawer (a sheet dialog). Restoring asks first. It is an undoable action in the sense that the current
 * state is itself snapshotted on the way past, but "undoable if you know that"
 * is not the same as "safe", and the person clicking it is usually not certain
 * which version they are picking.
 */
export default function RevisionDrawer({ revisions, restoreUrl }: Props) {
    const [open, setOpen] = useState(false)
    const [restoring, setRestoring] = useState<Revision | null>(null)

    return (
        <>
            <button type="button" className="kit-button" data-size="sm" onClick={() => setOpen(true)}>
                History ({revisions.length})
            </button>

            <Dialog open={open} onOpenChange={setOpen} variant="sheet" title="History" description="Every save is kept. Restoring one keeps the current version too.">
                {revisions.length === 0 ? (
                    <p>No revisions yet.</p>
                ) : (
                    <ol className="divide-y divide-line">
                        {revisions.map((revision) => (
                            <li key={revision.id} className="flex items-center justify-between gap-4 py-3">
                                <div className="min-w-0">
                                    <p className="truncate font-medium text-fg">{revision.title || 'Untitled'}</p>
                                    <p className="text-fg-subtle">
                                        {formatted(revision.createdAt)} &middot; {revision.status}
                                    </p>
                                </div>

                                <button type="button" className="kit-button" data-size="sm" onClick={() => setRestoring(revision)}>
                                    Restore
                                </button>
                            </li>
                        ))}
                    </ol>
                )}
            </Dialog>

            <Confirm
                open={restoring !== null}
                onOpenChange={(next) => !next && setRestoring(null)}
                title="Restore this version?"
                description="The current one is kept in the history."
                confirmLabel="Restore"
                onConfirm={() => restoring && router.post(restoreUrl(restoring))}
            />
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
