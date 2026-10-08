import { useEffect, useState } from 'react'
import ItemList, { newKey, withKeys, withoutKeys, type Item } from '../components/ItemList'
import FieldRenderer, { isShown } from './FieldRenderer'
import type { FieldProps, FieldSchema } from './types'

type Row = Record<string, unknown> & Item

/**
 * A repeating group of fields.
 *
 * Each row renders its subfields back through the same registry as a
 * top-level field, so every field type works inside a repeater, including
 * rich text and, in principle, another repeater.
 *
 * The rows the server sends are plain objects with no identity, and this keeps
 * a keyed copy in local state. See `Item._key` for why identity by array index
 * does not work once rows can be reordered.
 */
export default function RepeaterField({ field, value, onChange, error }: FieldProps<unknown[]>) {
    const [rows, setRows] = useState<Row[]>(() => withKeys<Record<string, unknown>>(value))

    /*
     * Resync when the value changes from outside.
     *
     * Restoring a revision or recovering a draft replaces the form state
     * underneath this field. Comparing the stripped rows against the incoming
     * value means an edit made here is recognised as already applied, so only
     * a genuine external change re-keys, and re-keying mid-typing does not
     * happen.
     */
    useEffect(() => {
        const mine = JSON.stringify(withoutKeys(rows))
        const theirs = JSON.stringify(Array.isArray(value) ? value : [])

        if (mine !== theirs) {
            setRows(withKeys<Record<string, unknown>>(value))
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [value])

    const update = (next: Row[]): void => {
        setRows(next)
        onChange(withoutKeys(next))
    }

    const setCell = (index: number, name: string, cell: unknown): void =>
        update(rows.map((row, i) => (i === index ? { ...row, [name]: cell } : row)))

    return (
        <>
            <ItemList<Row>
                items={rows}
                onChange={update}
                min={field.min}
                max={field.max}
                addLabel={`Add ${singular(field.label)}`}
                onAdd={() => ({ ...blankRow(field.fields ?? []), _key: newKey() })}
                summary={(row) => (
                    <span className="itemlist-title">{rowSummary(field, row)}</span>
                )}
            >
                {(row, index) => (
                    <div className="itemlist-fields">
                        {(field.fields ?? []).filter((sub) => isShown(sub, row)).map((sub) => (
                            <FieldRenderer
                                key={sub.name}
                                field={{ ...sub, name: `${field.name}.${index}.${sub.name}` }}
                                value={row[sub.name]}
                                values={row}
                                onChange={(_name, cell) => setCell(index, sub.name, cell)}
                            />
                        ))}
                    </div>
                )}
            </ItemList>

            {/*
                Server errors on a repeater arrive keyed by the row that
                failed, for example `items.2.question`. The row's own
                FieldRenderer never sees them, because it is handed a subfield
                schema rather than the form's error bag, so the message is
                shown here with its position intact.
            */}
            {error !== undefined && <p className="field-error">{error}</p>}
        </>
    )
}

/** A row with every subfield present, so no input starts uncontrolled. */
export function blankRow(fields: FieldSchema[]): Record<string, unknown> {
    const row: Record<string, unknown> = {}

    for (const field of fields) {
        row[field.name] = field.default ?? blankFor(field)
    }

    return row
}

function blankFor(field: FieldSchema): unknown {
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

/**
 * What a collapsed row says about itself.
 *
 * From the subfield the schema nominated with `rowLabel()`. A list of rows all
 * reading "Item 3" is a list you have to open one by one, which defeats the
 * point of collapsing.
 */
function rowSummary(field: FieldSchema, row: Record<string, unknown>): string {
    const key = field.rowLabel

    if (key !== null && key !== undefined) {
        const value = row[key]

        if (typeof value === 'string' && value.trim() !== '') {
            return value.length > 70 ? `${value.slice(0, 70)}...` : value
        }
    }

    return 'Untitled'
}

function singular(label: string): string {
    const lower = label.toLowerCase()

    return lower.endsWith('s') && !lower.endsWith('ss') ? lower.slice(0, -1) : lower
}
