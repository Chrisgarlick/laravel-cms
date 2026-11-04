/** A small set of mutually exclusive filters, e.g. All / Published / Drafts: kit's segmented control. */
export function Segmented<T extends string>({ value, options, onChange, label }: { value: T; options: Array<{ value: T; label: string; count?: number }>; onChange: (value: T) => void; label: string }) {
    return (
        <div role="radiogroup" aria-label={label} className="kit-segmented">
            {options.map((option) => (
                <button key={option.value} type="button" role="radio" aria-checked={option.value === value} onClick={() => onChange(option.value)}>
                    {option.label}
                    {option.count !== undefined && <span className="text-fg-subtle tabular-nums">{option.count}</span>}
                </button>
            ))}
        </div>
    )
}
