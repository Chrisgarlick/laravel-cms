import type { LintIssue } from '../hooks/useLivePreview'

/**
 * Brand voice findings for the entry being edited.
 *
 * Blocking issues are separated from warnings because they mean different
 * things: one stops the save, the other is an observation. A single list
 * sorted by severity reads as a list of problems, and an editor who cannot
 * tell which two of nine will actually block them learns to ignore all nine.
 */
export default function LintPanel({ issues, checking }: { issues: LintIssue[]; checking: boolean }) {
    const blocking = issues.filter((issue) => issue.severity === 'block')
    const warnings = issues.filter((issue) => issue.severity === 'warn')

    return (
        <section className="panel">
            <h2 className="panel-title">
                Brand voice
                {checking && <span className="panel-busy"> checking</span>}
            </h2>

            {issues.length === 0 && <p className="panel-ok">Nothing to flag.</p>}

            {blocking.length > 0 && (
                <>
                    <p className="panel-label panel-label-block">
                        {blocking.length} {blocking.length === 1 ? 'issue' : 'issues'} will stop
                        this saving
                    </p>
                    <ul className="panel-list">
                        {blocking.map((issue, index) => (
                            <Issue key={`${issue.rule}-${index}`} issue={issue} />
                        ))}
                    </ul>
                </>
            )}

            {warnings.length > 0 && (
                <>
                    <p className="panel-label">
                        {warnings.length} {warnings.length === 1 ? 'warning' : 'warnings'}
                    </p>
                    <ul className="panel-list">
                        {warnings.map((issue, index) => (
                            <Issue key={`${issue.rule}-${index}`} issue={issue} />
                        ))}
                    </ul>
                </>
            )}
        </section>
    )
}

function Issue({ issue }: { issue: LintIssue }) {
    return (
        <li className={`panel-issue is-${issue.severity}`}>
            <p className="panel-issue-message">{issue.message}</p>

            <p className="panel-issue-field">
                {issue.field} <span className="panel-rule">{issue.rule}</span>
            </p>

            {issue.excerpt !== '' && <p className="panel-issue-excerpt">{issue.excerpt}</p>}

            {/*
                The rewrite, where the rule can produce one. A rule that can
                only say "this is wrong" is a rule people learn to work around.
            */}
            {issue.suggestion !== null && (
                <p className="panel-issue-fix">Try: {issue.suggestion}</p>
            )}
        </li>
    )
}
