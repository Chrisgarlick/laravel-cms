import { cn } from './cn'

/** A small set of mutually exclusive filters, e.g. All / Published / Drafts. */
export function Segmented<T extends string>({ value, options, onChange, label }: { value: T; options: Array<{ value: T; label: string; count?: number }>; onChange: (value: T) => void; label: string }) {
    return (
        <div role="radiogroup" aria-label={label} className="inline-flex h-8 items-center gap-0.5 rounded-[var(--radius-control)] bg-sunken p-0.5">
            {options.map((option) => {
                const active = option.value === value

                return (
                    <button
                        key={option.value}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'inline-flex h-7 items-center gap-1.5 rounded-[5px] px-2.5 text-[12.5px] font-medium transition-[background-color,color,box-shadow]',
                            active ? 'bg-panel text-fg shadow-sm' : 'text-fg-muted hover:text-fg',
                        )}
                    >
                        {option.label}
                        {option.count !== undefined && <span className={cn('tabular-nums', active ? 'text-fg-subtle' : 'text-fg-subtle/80')}>{option.count}</span>}
                    </button>
                )
            })}
        </div>
    )
}
