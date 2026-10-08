import type { FieldProps } from './types'

export default function SelectField({ field, value, onChange }: FieldProps<string>) {
    return (
        <select
            id={`field-${field.name}`}
            className="field-input field-narrow"
            value={value ?? ''}
            required={field.required}
            onChange={(event) => onChange(event.target.value)}
        >
            {/*
                An empty option only where empty is allowed. Offering one on a
                required select invites a value the server will reject.
            */}
            {!field.required && <option value="">Not set</option>}

            {field.options.map((option) => (
                <option key={option} value={option}>
                    {option}
                </option>
            ))}
        </select>
    )
}
