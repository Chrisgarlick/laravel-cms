import type { FieldProps } from './types'

export default function TextField({ field, value, onChange }: FieldProps<string>) {
    return (
        <input
            id={`field-${field.name}`}
            type={field.type === 'url' ? 'url' : 'text'}
            className="field-input"
            value={value ?? ''}
            maxLength={field.maxLength ?? undefined}
            required={field.required}
            onChange={(event) => onChange(event.target.value)}
        />
    )
}
