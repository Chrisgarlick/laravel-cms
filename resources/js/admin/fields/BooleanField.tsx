import type { FieldProps } from './types'

export default function BooleanField({ field, value, onChange }: FieldProps<boolean>) {
    return (
        <label className="kit-choice">
            <input
                id={`field-${field.name}`}
                type="checkbox"
                role="switch"
                className="kit-switch"
                checked={Boolean(value)}
                onChange={(event) => onChange(event.target.checked)}
            />
            {field.label}
        </label>
    )
}
