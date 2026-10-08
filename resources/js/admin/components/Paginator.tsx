import { ChevronLeft, ChevronRight } from 'lucide-react'

interface Props {
    currentPage: number
    lastPage: number
    onGo: (page: number) => void
}

/**
 * Page controls for a server-paginated table.
 *
 * Buttons rather than links, because every table in the admin navigates with
 * an Inertia partial reload and a real anchor would cost a full page visit.
 *
 * The window is deliberately small: first, last, and two either side of where
 * you are. A collection with forty pages renders forty links otherwise, which
 * is more pagination than table.
 */
export default function Paginator({ currentPage, lastPage, onGo }: Props) {
    if (lastPage <= 1) {
        return null
    }

    const pages = windowed(currentPage, lastPage)
    const button = 'inline-flex h-7 min-w-7 items-center justify-center rounded-[var(--radius-control)] px-2 text-[12.5px] tabular-nums transition-colors disabled:pointer-events-none disabled:opacity-40'

    return (
        <nav className="mt-4 flex items-center justify-between gap-2" aria-label="Pagination">
            <span className="text-[12.5px] text-fg-subtle">
                Page {currentPage} of {lastPage}
            </span>

            <div className="flex items-center gap-0.5">
                <button type="button" className={`${button} text-fg-muted hover:bg-hover hover:text-fg`} disabled={currentPage === 1} onClick={() => onGo(currentPage - 1)} aria-label="Previous page">
                    <ChevronLeft className="size-4" />
                </button>

                {pages.map((page, index) =>
                    page === null ? (
                        // eslint-disable-next-line react/no-array-index-key
                        <span key={`gap-${index}`} className="px-1 text-fg-subtle" aria-hidden>
                            …
                        </span>
                    ) : (
                        <button
                            key={page}
                            type="button"
                            className={`${button} ${page === currentPage ? 'bg-selected font-medium text-fg' : 'text-fg-muted hover:bg-hover hover:text-fg'}`}
                            aria-current={page === currentPage ? 'page' : undefined}
                            onClick={() => onGo(page)}
                        >
                            {page}
                        </button>
                    ),
                )}

                <button type="button" className={`${button} text-fg-muted hover:bg-hover hover:text-fg`} disabled={currentPage === lastPage} onClick={() => onGo(currentPage + 1)} aria-label="Next page">
                    <ChevronRight className="size-4" />
                </button>
            </div>
        </nav>
    )
}

/** Page numbers to show, with nulls where a run was skipped. */
function windowed(current: number, last: number): Array<number | null> {
    const pages = new Set([1, last, current, current - 1, current + 1, current - 2, current + 2])
    const shown = [...pages].filter((page) => page >= 1 && page <= last).sort((a, b) => a - b)

    const out: Array<number | null> = []
    let previous = 0

    for (const page of shown) {
        if (previous !== 0 && page - previous > 1) {
            out.push(null)
        }

        out.push(page)
        previous = page
    }

    return out
}
