import type { FieldProps } from './types'

export interface SeoValue {
    title: string
    description: string
    canonical: string
    og_image: string | null
    keywords: string
    noindex: boolean
    nofollow: boolean
}

/**
 * Per-entry SEO overrides.
 *
 * Every input here is an override, and leaving one empty is the normal case:
 * the resolver falls back to the collection's template and then to the site
 * default, so a filled-in title is a decision to differ rather than a
 * requirement. The placeholders say so, because an empty required-looking
 * field invites somebody to paste the page title into it and defeat the
 * fallback chain.
 *
 * What this panel deliberately does not show is the resolved result. That is
 * in the SEO panel beside the form, rendered by the same resolver the front
 * end uses, so there is one answer to "what will Google see" rather than two.
 */
export default function SeoField({ value, onChange }: FieldProps<Partial<SeoValue> | null>) {
    const seo: SeoValue = {
        title: '',
        description: '',
        canonical: '',
        og_image: null,
        keywords: '',
        noindex: false,
        nofollow: false,
        ...(value ?? {}),
    }

    const set = <K extends keyof SeoValue>(key: K, next: SeoValue[K]): void =>
        onChange({ ...seo, [key]: next })

    return (
        <div className="field-seo">
            <label className="field-sub">
                Title override
                <input
                    type="text"
                    className="field-input"
                    value={seo.title}
                    placeholder="Falls back to the collection template"
                    onChange={(event) => set('title', event.target.value)}
                />
                {seo.title.length > 60 && (
                    <span className="field-warn">
                        {seo.title.length} characters. Google truncates around 60.
                    </span>
                )}
            </label>

            <label className="field-sub">
                Description override
                <textarea
                    className="field-input field-textarea"
                    rows={3}
                    value={seo.description}
                    placeholder="Falls back to the summary field, then the site default"
                    onChange={(event) => set('description', event.target.value)}
                />
                {seo.description.length > 155 && (
                    <span className="field-warn">
                        {seo.description.length} characters. Google truncates around 155.
                    </span>
                )}
            </label>

            <label className="field-sub">
                Canonical override
                <input
                    type="url"
                    className="field-input field-mono"
                    value={seo.canonical}
                    placeholder="Falls back to this entry's own URL"
                    onChange={(event) => set('canonical', event.target.value)}
                />
            </label>

            <label className="field-sub">
                Keywords
                <input
                    type="text"
                    className="field-input"
                    value={seo.keywords}
                    placeholder="Primary first, then any others, comma separated"
                    onChange={(event) => set('keywords', event.target.value)}
                />
                <span className="field-help">
                    The first is checked for in the title, the opening and an h2.
                </span>
            </label>

            <div className="field-robots">
                <label className="field-check">
                    <input
                        type="checkbox"
                        checked={seo.noindex}
                        onChange={(event) => set('noindex', event.target.checked)}
                    />
                    noindex
                </label>

                <label className="field-check">
                    <input
                        type="checkbox"
                        checked={seo.nofollow}
                        onChange={(event) => set('nofollow', event.target.checked)}
                    />
                    nofollow
                </label>
            </div>

            {seo.noindex && (
                // Worth stating, because the two effects are easy to conflate
                // and one of them is not reversible by unticking the box.
                <p className="field-warn">
                    This page will be left out of the sitemap and asked not to be indexed.
                    Search engines can take weeks to drop a page they already have.
                </p>
            )}
        </div>
    )
}
