import type { FieldProps } from './types'

export default function BooleanField({ field, value, onChange }: FieldProps<boolean>) {
    return (
        <label className="field-check">
            <input
                id={`field-${field.name}`}
                type="checkbox"
                checked={Boolean(value)}
                onChange={(event) => onChange(event.target.checked)}
            />
            {field.label}
        </label>
    )
}
