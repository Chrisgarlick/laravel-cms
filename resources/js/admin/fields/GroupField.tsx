import FieldRenderer, { isShown } from './FieldRenderer'
import { blankRow } from './RepeaterField'
import type { FieldProps } from './types'

/**
 * A named set of fields stored as one object: `Field::group('cta')`.
 *
 * Renders as a titled card holding its subfields, each through the same
 * registry as a top-level field. Conditions inside a group look at the
 * group's own values, matching how the server validates them.
 */
export default function GroupField({ field, value, onChange }: FieldProps<Record<string, unknown> | null>) {
    const current = { ...blankRow(field.fields ?? []), ...(value ?? {}) }

    return (
        <fieldset className="rounded-[var(--radius-card)] border border-line bg-panel shadow-sm">
            <legend className="sr-only">{field.label}</legend>
            <div className="border-b border-line px-4 py-2.5">
                <div className="text-[12.5px] font-semibold">{field.label}</div>
                {field.help !== null && <p className="mt-0.5 text-[12px] text-fg-subtle">{field.help}</p>}
            </div>
            <div className="flex flex-col gap-4 p-4">
                {(field.fields ?? []).filter((sub) => isShown(sub, current)).map((sub) => (
                    <FieldRenderer
                        key={sub.name}
                        field={{ ...sub, name: `${field.name}.${sub.name}` }}
                        value={current[sub.name]}
                        values={current}
                        onChange={(_name, next) => onChange({ ...current, [sub.name]: next })}
                    />
                ))}
            </div>
        </fieldset>
    )
}
