import type { FieldSchema } from './types'

/**
 * What a field holds before anybody has typed into it: its default, or the
 * empty value of its type.
 *
 * The server casts a default to its field's type (Field::defaultValue()), so
 * a number field's default arrives as a number and a toggle's as true or
 * false. Cast again here all the same: a schema serialised by an older
 * package, or written by hand, can still carry '3' or '1', and a number input
 * holding the string '3' saves a string. A list default (a repeater's rows)
 * is copied, so two new blocks never share one array.
 */
export function initialValue(field: FieldSchema): unknown {
    const value = field.default

    if (value === null || value === undefined) {
        return blankFor(field)
    }

    switch (field.type) {
        case 'number': {
            const number = typeof value === 'number' ? value : Number(value)

            return typeof value === 'boolean' || value === '' || Number.isNaN(number) ? null : number
        }
        case 'boolean':
            return typeof value === 'string' ? ['1', 'true', 'yes', 'on'].includes(value.trim().toLowerCase()) : Boolean(value)
        case 'repeater':
        case 'blocks':
        case 'group':
        case 'media':
            return typeof value === 'object' ? structuredClone(value) : blankFor(field)
        default:
            return typeof value === 'object' ? blankFor(field) : String(value)
    }
}

/** Every field present, at its initial value, so no input starts uncontrolled. */
export function initialValues(fields: FieldSchema[]): Record<string, unknown> {
    const values: Record<string, unknown> = {}

    for (const field of fields) {
        values[field.name] = initialValue(field)
    }

    return values
}

/** The empty value of a field's type. */
export function blankFor(field: FieldSchema): unknown {
    switch (field.type) {
        case 'boolean':
            return false
        case 'repeater':
        case 'blocks':
            return []
        case 'group':
            return {}
        case 'relation':
            return field.many ? [] : null
        case 'number':
        case 'datetime':
        case 'media':
        case 'richText':
            return null
        default:
            return ''
    }
}
