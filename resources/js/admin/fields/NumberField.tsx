import type { FieldProps } from './types'

export default function NumberField({ field, value, onChange }: FieldProps<number | null>) {
    return (
        <input
            id={`field-${field.name}`}
            type="number"
            className="field-input field-narrow"
            value={value ?? ''}
            required={field.required}
            onChange={(event) => {
                // An empty input is null, not zero. Sending zero would make
                // "no sort order" indistinguishable from "first".
                const next = event.target.value

                onChange(next === '' ? null : Number(next))
            }}
        />
    )
}
