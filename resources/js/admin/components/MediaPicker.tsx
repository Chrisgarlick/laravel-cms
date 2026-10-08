import { usePage } from '@inertiajs/react'
import { useCallback, useEffect, useRef, useState } from 'react'
import Dropzone from '@/components/Dropzone'
import Paginator from '@/components/Paginator'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { useUploader, type MediaItem } from '@/hooks/useUploader'
import type { SharedProps } from '@/types'

interface Page {
    data: MediaItem[]
    currentPage: number
    lastPage: number
}

/**
 * Choose an image from the library, or upload one, without leaving the entry.
 *
 * A native <dialog> opened with showModal(), which brings focus trapping,
 * Escape to close and an inert page behind it for free. Rebuilding those by
 * hand is where most modal pickers quietly fail keyboard users.
 *
 * Talks to the library over JSON rather than with Inertia visits, because an
 * Inertia visit from inside the editor would navigate away from the form the
 * picker was opened to fill in.
 */
export default function MediaPicker({
    open,
    onClose,
    onPick,
}: {
    open: boolean
    onClose: () => void
    onPick: (media: MediaItem) => void
}) {
    const { cms } = usePage<SharedProps>().props
    const base = `${cms.prefix}/media`
    const dialog = useRef<HTMLDialogElement>(null)

    const [q, setQ] = useState('')
    const [page, setPage] = useState(1)
    const [result, setResult] = useState<Page | null>(null)
    const [failed, setFailed] = useState(false)
    const settled = useDebouncedValue(q, 300)

    useEffect(() => {
        const element = dialog.current

        if (element === null) {
            return
        }

        if (open && !element.open) {
            element.showModal()
        } else if (!open && element.open) {
            element.close()
        }
    }, [open])

    const load = useCallback((): void => {
        const params = new URLSearchParams({ page: String(page) })

        if (settled !== '') {
            params.set('q', settled)
        }

        void fetch(`${base}/browse?${params.toString()}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
            .then((body: Page) => {
                setResult(body)
                setFailed(false)
            })
            .catch(() => setFailed(true))
    }, [base, page, settled])

    useEffect(() => {
        if (open) {
            load()
        }
    }, [open, load])

    // A new search starts from the first page, not from page four of the old one.
    useEffect(() => setPage(1), [settled])

    const uploader = useUploader({
        url: base,
        maxKb: cms.media.maxKb,
        onFinished: load,
    })

    return (
        <dialog ref={dialog} className="picker" onClose={onClose} aria-label="Choose an image">
            <header className="drawer-head">
                <h2 className="drawer-title">Choose an image</h2>
                <button type="button" className="button-quiet" onClick={onClose}>
                    Close
                </button>
            </header>

            <Dropzone accept={cms.media.accept} uploads={uploader.uploads} onFiles={uploader.add} onClear={uploader.clear} />

            <input
                type="search"
                className="field-input"
                placeholder="Search filename or alt text"
                value={q}
                onChange={(event) => setQ(event.target.value)}
                aria-label="Search media"
            />

            {failed && <p className="field-error">The library could not be loaded. Close and try again.</p>}

            {result === null && !failed && <p className="field-loading">Loading</p>}

            {result !== null && result.data.length === 0 && (
                <p className="panel-help">{settled === '' ? 'The library is empty. Upload an image above.' : 'Nothing matches.'}</p>
            )}

            {result !== null && result.data.length > 0 && (
                <ul className="media-grid">
                    {result.data.map((item) => (
                        <li key={item.id}>
                            <button
                                type="button"
                                className="media-tile"
                                onClick={() => {
                                    onPick(item)
                                    onClose()
                                }}
                            >
                                <img src={item.thumb} alt="" loading="lazy" width={200} height={200} />
                                <span className="media-tile-name">{item.filename}</span>
                                {!item.alt && (
                                    <span className="media-tile-meta">
                                        <span className="pill is-warn">no alt</span>
                                    </span>
                                )}
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {result !== null && <Paginator currentPage={result.currentPage} lastPage={result.lastPage} onGo={setPage} />}
        </dialog>
    )
}
