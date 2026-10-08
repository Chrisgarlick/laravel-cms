import type { FieldProps } from './types'

/**
 * A datetime, stored as ISO 8601 and edited in local time.
 *
 * `datetime-local` gives a value with no zone, so it has to be interpreted as
 * the editor's own and converted back to an absolute instant. Sending the
 * naive string straight to the server would store "14:00" as UTC and publish
 * an article an hour early every summer.
 */
export default function DatetimeField({ field, value, onChange }: FieldProps<string | null>) {
    const local = value === null || value === undefined || value === '' ? '' : toLocalInput(value)

    return (
        <input
            id={`field-${field.name}`}
            type="datetime-local"
            className="field-input field-narrow"
            value={local}
            required={field.required}
            onChange={(event) => {
                const next = event.target.value

                onChange(next === '' ? null : new Date(next).toISOString())
            }}
        />
    )
}

function toLocalInput(iso: string): string {
    const date = new Date(iso)

    if (Number.isNaN(date.getTime())) {
        return ''
    }

    // Shift by the local offset so toISOString slices out local wall-clock
    // time rather than UTC, which is what the input expects.
    const shifted = new Date(date.getTime() - date.getTimezoneOffset() * 60_000)

    return shifted.toISOString().slice(0, 16)
}
