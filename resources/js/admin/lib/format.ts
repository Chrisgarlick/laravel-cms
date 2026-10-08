/** "12 Mar 2026", or '' for a missing or unparseable date. */
export function formatDate(iso: string | null | undefined): string {
    if (!iso) return ''

    const date = new Date(iso)

    return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
}

/** "just now", "5m ago", "3h ago", "2d ago", then the date. */
export function timeAgo(iso: string | null | undefined): string {
    if (!iso) return ''

    const date = new Date(iso)
    const seconds = Math.round((Date.now() - date.getTime()) / 1000)

    if (Number.isNaN(seconds)) return ''
    if (seconds < 60) return 'just now'
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`
    if (seconds < 86400 * 7) return `${Math.floor(seconds / 86400)}d ago`

    return formatDate(iso)
}
