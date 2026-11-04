import type { FieldProps } from './types'

export default function TextareaField({ field, value, onChange }: FieldProps<string>) {
    const length = (value ?? '').length

    return (
        <>
            <textarea
                id={`field-${field.name}`}
                className="kit-input"
                value={value ?? ''}
                rows={4}
                maxLength={field.maxLength ?? undefined}
                required={field.required}
                onChange={(event) => onChange(event.target.value)}
            />

            {/*
                A live count, but only where there is a limit to count towards.
                An excerpt that silently truncates at 300 characters on save is
                the kind of thing an editor discovers from the live site.
            */}
            {field.maxLength !== null && (
                <p className={`field-count${length > field.maxLength * 0.9 ? ' is-close' : ''}`}>
                    {length} / {field.maxLength}
                </p>
            )}
        </>
    )
}
