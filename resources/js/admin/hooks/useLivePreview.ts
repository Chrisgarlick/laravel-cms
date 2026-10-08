import { useEffect, useState } from 'react'
import { useDebouncedValue } from './useDebouncedValue'

export interface LintIssue {
    rule: string
    field: string
    severity: 'block' | 'warn'
    message: string
    excerpt: string
    suggestion: string | null
}

export interface SeoPreview {
    title: string
    description: string
    canonical: string
    image: string | null
    imageAlt: string | null
    type: string
    robots: string | null
    publishedAt: string | null
    modifiedAt: string | null
}

interface Result {
    lint: LintIssue[]
    seo: SeoPreview | null

    /** The section 5.7 SEO gates. `block` severity stops publishing, not saving. */
    seoChecks: LintIssue[]
    checking: boolean
}

interface Initial {
    lint: LintIssue[]
    seo: SeoPreview | null
    seoChecks: LintIssue[]
}

/**
 * Lint and SEO for what is currently in the form.
 *
 * The checking happens on the server, against the real linter and the real SEO
 * resolver. Reimplementing either in TypeScript would mean two definitions of
 * the brand voice, and experience says the one in the browser is the one that
 * drifts: the rules would agree on the day they were written and disagree
 * within a month, with the editor showing green for content the save then
 * rejects.
 *
 * The cost is a request per pause in typing, which on an admin with one user
 * is not a cost.
 */
export function useLivePreview(
    url: string,
    values: Record<string, unknown>,
    initial: Initial,
    entryId: number | null = null,
): Result {
    const [result, setResult] = useState<Result>({ ...initial, checking: false })
    const settled = useDebouncedValue(values, 700)

    useEffect(() => {
        let cancelled = false

        setResult((previous) => ({ ...previous, checking: true }))

        void fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ??
                    '',
            },
            // The entry id lets the server lay the form over the stored row,
            // so the duplicate check does not find this entry and call it a
            // duplicate of itself.
            body: JSON.stringify({ values: settled, entry: entryId }),
        })
            .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
            .then((body: Initial) => {
                // A slower earlier request must not overwrite a newer result.
                if (!cancelled) {
                    setResult({ lint: body.lint, seo: body.seo, seoChecks: body.seoChecks ?? [], checking: false })
                }
            })
            .catch(() => {
                if (!cancelled) {
                    // Leave the last good result on screen. A panel that
                    // empties itself on a dropped request reads as "no
                    // problems found", which is the opposite of what happened.
                    setResult((previous) => ({ ...previous, checking: false }))
                }
            })

        return () => {
            cancelled = true
        }
    }, [url, settled, entryId])

    return result
}
