import type { MouseEvent } from 'react'

interface Props {
    currentPage: number
    lastPage: number
    onGo: (page: number) => void
}

/**
 * Page controls for a server-paginated table.
 *
 * kit's pagination. Links, so a page can open in a new tab, but a plain click
 * is intercepted: every table in the admin navigates with an Inertia partial
 * reload, and following the anchor would cost a full page visit.
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

    // Real links (kit's pagination styles anchors), so a page opens in a new
    // tab; a plain click stays an Inertia partial reload.
    const link = (page: number) => {
        const url = new URL(window.location.href)
        url.searchParams.set('page', String(page))

        return url.pathname + url.search
    }
    const go = (page: number) => (event: MouseEvent) => {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return
        event.preventDefault()
        onGo(page)
    }

    return (
        <nav className="mt-4 flex items-center justify-between gap-2" aria-label="Pagination">
            <span className="text-[12.5px] text-fg-subtle">
                Page {currentPage} of {lastPage}
            </span>

            <ul className="kit-pagination">
                <li>{currentPage === 1 ? <span aria-disabled="true">Previous</span> : <a href={link(currentPage - 1)} rel="prev" onClick={go(currentPage - 1)}>Previous</a>}</li>
                {pages.map((page, index) =>
                    page === null ? (
                        // eslint-disable-next-line react/no-array-index-key
                        <li key={`gap-${index}`} className="kit-pagination-gap" aria-hidden>…</li>
                    ) : (
                        <li key={page}>
                            <a href={link(page)} aria-current={page === currentPage ? 'page' : undefined} onClick={go(page)}>{page}</a>
                        </li>
                    ),
                )}
                <li>{currentPage === lastPage ? <span aria-disabled="true">Next</span> : <a href={link(currentPage + 1)} rel="next" onClick={go(currentPage + 1)}>Next</a>}</li>
            </ul>
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
