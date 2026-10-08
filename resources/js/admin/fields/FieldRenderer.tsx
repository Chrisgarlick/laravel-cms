import { resolveField } from './registry'
import type { FieldSchema } from './types'

interface Props {
    field: FieldSchema
    value: unknown
    values: Record<string, unknown>
    error?: string
    onChange: (name: string, value: unknown) => void
}

/**
 * Whether a field's `showWhen()` condition holds against its siblings.
 *
 * A boolean sibling compares by truthiness, so `showWhen('featured', true)`
 * works whether the value arrived as `true`, `1` or `"1"`.
 */
export function isShown(field: FieldSchema, siblings: Record<string, unknown>): boolean {
    const condition = field.condition

    if (condition === null || condition === undefined) return true

    const actual = siblings[condition.field]

    return condition.values.some((expected) =>
        typeof expected === 'boolean' ? Boolean(actual) === expected && actual !== '0' : String(actual ?? '') === String(expected),
    )
}

/**
 * The label, help text and error around whichever component the type resolves
 * to.
 *
 * Kept out of the field components so that adding a field type is one
 * component with one job, and so that every field in the admin is labelled and
 * associated the same way. A component that had to render its own label would
 * be a component that could forget the `htmlFor`.
 */
export default function FieldRenderer({ field, value, values, error, onChange }: Props) {
    const Component = resolveField(field.type)

    // The boolean input renders its own label beside the switch, and a group
    // renders its label as a heading over its own fields.
    const labelled = field.type !== 'boolean' && field.type !== 'group'

    return (
        <div id={labelled ? undefined : `field-${field.name}`} className={`field field-type-${field.type}${error ? ' has-error' : ''} animate-in`}>
            {labelled && (
                <label className="field-label" htmlFor={`field-${field.name}`}>
                    {field.label}
                    {field.required && <span className="field-required">Required</span>}
                </label>
            )}

            <Component
                field={field}
                value={value as never}
                values={values}
                error={error}
                onChange={(next: unknown) => onChange(field.name, next)}
            />

            {field.help !== null && field.type !== 'group' && <p className="field-help">{field.help}</p>}

            {error !== undefined && <p className="field-error">{error}</p>}
        </div>
    )
}
