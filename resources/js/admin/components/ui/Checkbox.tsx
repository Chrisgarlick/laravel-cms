import { Check, Minus } from 'lucide-react'
import { cn } from './cn'

/** A styled native checkbox. `indeterminate` shows the partial state used by "select all". */
export function Checkbox({ checked, indeterminate = false, onChange, label, className }: { checked: boolean; indeterminate?: boolean; onChange: (checked: boolean) => void; label: string; className?: string }) {
    const on = checked || indeterminate

    return (
        <label className={cn('relative inline-flex size-4 shrink-0 cursor-pointer items-center justify-center align-middle', className)}>
            <input
                type="checkbox"
                aria-label={label}
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
                className="peer absolute inset-0 cursor-pointer opacity-0"
            />
            <span
                className={cn(
                    'flex size-4 items-center justify-center rounded-[4px] border shadow-sm transition-colors peer-focus-visible:ring-3 peer-focus-visible:ring-[var(--accent-ring)]',
                    on ? 'border-accent bg-accent text-on-accent' : 'border-line-strong bg-panel',
                )}
                aria-hidden
            >
                {indeterminate ? <Minus className="size-3" strokeWidth={3} /> : checked && <Check className="size-3" strokeWidth={3} />}
            </span>
        </label>
    )
}
