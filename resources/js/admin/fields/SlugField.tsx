import { useState } from 'react'
import type { FieldProps } from './types'

/** The same transformation Str::slug applies, so the preview does not lie. */
function slugify(value: string): string {
    return value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
}

/**
 * A slug, derived from its source field until somebody edits it.
 *
 * The derivation stops permanently on the first manual edit, and it never
 * starts again for an entry that already has a slug. That second rule is the
 * important one: changing the title of a published article must not silently
 * change its URL. Renaming a slug creates a 301 automatically, so nothing
 * breaks, but a redirect nobody asked for is still a surprise.
 */
export default function SlugField({ field, value, onChange, values }: FieldProps<string>) {
    const [locked, setLocked] = useState(() => Boolean(value))

    const source = field.slugSource ?? 'title'
    const sourceValue = String(values[source] ?? '')
    const derived = slugify(sourceValue)

    // Deriving during render rather than in an effect. An effect would write
    // state during a commit and make the input lag one keystroke behind.
    const current = locked ? (value ?? '') : derived

    if (!locked && current !== value) {
        // Keep the parent in step without waiting for a change event, so
        // saving immediately after typing a title stores the derived slug.
        queueMicrotask(() => onChange(derived))
    }

    return (
        <>
            <input
                id={`field-${field.name}`}
                type="text"
                className="field-input field-mono"
                value={current}
                required={field.required}
                onChange={(event) => {
                    setLocked(true)
                    onChange(slugify(event.target.value))
                }}
            />

            {field.preview !== null && field.preview !== undefined && (
                <p className="field-help field-mono">
                    {field.preview.replace('{slug}', current || 'untitled')}
                </p>
            )}

            {locked && Boolean(derived) && derived !== current && (
                <button
                    type="button"
                    className="field-link"
                    onClick={() => {
                        setLocked(false)
                        onChange(derived)
                    }}
                >
                    Reset to &ldquo;{derived}&rdquo;
                </button>
            )}
        </>
    )
}
