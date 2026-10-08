import type { LintIssue, SeoPreview } from '../hooks/useLivePreview'

/**
 * What a search result for this entry would look like, and what is wrong with it.
 *
 * The preview is resolved by the server's own SeoResolver, so it is the actual
 * output of the fallback chain rather than a second implementation of it. The
 * checks below it are the section 5.7 gates, also run on the server, against
 * the same rendered HTML a save would store.
 *
 * Findings that block publishing are separated from the rest and say so in
 * terms of publishing, not saving, because that is what they stop. A draft
 * with a second h1 saves fine; it just cannot go live until it is fixed.
 */
export default function SeoPanel({
    seo,
    checks,
    publishing,
}: {
    seo: SeoPreview | null
    checks: LintIssue[]
    publishing: boolean
}) {
    if (seo === null) {
        return (
            <section className="panel">
                <h2 className="panel-title">Search preview</h2>
                <p className="panel-help">
                    This collection has no public URL, so there is nothing to index.
                </p>
            </section>
        )
    }

    const blocking = checks.filter((check) => check.severity === 'block')
    const warnings = checks.filter((check) => check.severity === 'warn')

    return (
        <section className="panel">
            <h2 className="panel-title">Search preview</h2>

            <div className="serp">
                <p className="serp-url">{seo.canonical}</p>
                <p className="serp-title">{seo.title}</p>
                <p className="serp-description">{seo.description || 'No description resolved.'}</p>
            </div>

            <dl className="panel-meta">
                <Measure label="Title" length={seo.title.length} limit={60} />
                <Measure label="Description" length={seo.description.length} limit={155} />
            </dl>

            {seo.robots !== null && (
                <p className="panel-warn">
                    Robots: {seo.robots}. This page is asking not to be indexed.
                </p>
            )}

            {seo.image === null && (
                <p className="panel-help">
                    No social image resolved, so a shared link will use the site default.
                </p>
            )}

            <h3 className="panel-title">SEO checks</h3>

            {checks.length === 0 && <p className="panel-ok">Nothing to flag.</p>}

            {blocking.length > 0 && (
                <>
                    <p className={`panel-label${publishing ? ' panel-label-block' : ''}`}>
                        {blocking.length} {blocking.length === 1 ? 'issue stops' : 'issues stop'} this
                        being published
                        {!publishing && '. It can still be saved as a draft.'}
                    </p>
                    <ul className="panel-list">
                        {blocking.map((check, index) => (
                            <Check key={`${check.rule}-${index}`} check={check} />
                        ))}
                    </ul>
                </>
            )}

            {warnings.length > 0 && (
                <>
                    <p className="panel-label">
                        {warnings.length} {warnings.length === 1 ? 'suggestion' : 'suggestions'}
                    </p>
                    <ul className="panel-list">
                        {warnings.map((check, index) => (
                            <Check key={`${check.rule}-${index}`} check={check} />
                        ))}
                    </ul>
                </>
            )}
        </section>
    )
}

function Check({ check }: { check: LintIssue }) {
    return (
        <li className={`panel-issue is-${check.severity}`}>
            <p className="panel-issue-message">{check.message}</p>
            <p className="panel-issue-field">
                {check.field} <span className="panel-rule">{check.rule}</span>
            </p>
            {check.excerpt !== '' && <p className="panel-issue-excerpt">{check.excerpt}</p>}
            {check.suggestion !== null && <p className="panel-issue-fix">Try: {check.suggestion}</p>}
        </li>
    )
}

function Measure({ label, length, limit }: { label: string; length: number; limit: number }) {
    const over = length > limit

    return (
        <>
            <dt>{label}</dt>
            <dd className={over ? 'is-over' : undefined}>
                {length} / {limit}
                {over && ' will be truncated'}
            </dd>
        </>
    )
}
