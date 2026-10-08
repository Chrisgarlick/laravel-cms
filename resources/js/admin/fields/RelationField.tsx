import { useMemo, useState } from 'react'
import type { FieldProps } from './types'

/**
 * References to entries in another collection, stored as slugs.
 *
 * Slugs rather than ids, as Field::relation() explains: they survive a content
 * re-import and they make the stored jsonb readable when debugging. The cost
 * is that renaming a slug has to update referrers, which the slug-history
 * observer handles.
 *
 * A filter box over a checklist rather than a multi-select. A native
 * multi-select needs ctrl-click to add a second item and loses the whole
 * selection on a mis-click, which on a field that is usually three tags out of
 * sixty is the wrong trade.
 */
export default function RelationField({ field, value, onChange }: FieldProps<string[] | string | null>) {
    const [filter, setFilter] = useState('')

    const choices = field.choices ?? []
    const selected = useMemo(
        () => new Set(field.many ? ((value as string[] | null) ?? []) : value ? [value as string] : []),
        [field.many, value],
    )

    const visible = useMemo(() => {
        const needle = filter.trim().toLowerCase()

        return needle === ''
            ? choices
            : choices.filter((choice) => choice.label.toLowerCase().includes(needle))
    }, [choices, filter])

    if (choices.length === 0) {
        return (
            <p className="field-help">
                Nothing to link to yet. Create an entry in{' '}
                <strong>{field.relatedCollection}</strong> first.
            </p>
        )
    }

    const toggle = (slug: string): void => {
        if (!field.many) {
            onChange(selected.has(slug) ? null : slug)

            return
        }

        const next = new Set(selected)

        next.has(slug) ? next.delete(slug) : next.add(slug)

        onChange([...next])
    }

    return (
        <div className="field-relation">
            {choices.length > 8 && (
                <input
                    type="search"
                    className="field-input"
                    placeholder={`Filter ${choices.length} options`}
                    value={filter}
                    onChange={(event) => setFilter(event.target.value)}
                />
            )}

            <ul className="field-choices">
                {visible.map((choice) => (
                    <li key={choice.value}>
                        <label className="field-check">
                            <input
                                type={field.many ? 'checkbox' : 'radio'}
                                name={`field-${field.name}`}
                                checked={selected.has(choice.value)}
                                onChange={() => toggle(choice.value)}
                            />
                            {choice.label}
                        </label>
                    </li>
                ))}
            </ul>

            {/*
                Selected items that the filter is hiding, named rather than
                merely counted. "3 selected" while showing none of them is how
                somebody saves an entry having silently dropped a tag.
            */}
            {selected.size > 0 && visible.length < choices.length && (
                <p className="field-help">
                    Selected: {[...selected].join(', ')}
                </p>
            )}
        </div>
    )
}
