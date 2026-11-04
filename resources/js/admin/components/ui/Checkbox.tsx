import { useEffect, useRef } from 'react'
import { cn } from './cn'

/** kit's checkbox. `indeterminate` shows the partial state used by "select all". */
export function Checkbox({ checked, indeterminate = false, onChange, label, className }: { checked: boolean; indeterminate?: boolean; onChange: (checked: boolean) => void; label: string; className?: string }) {
    const ref = useRef<HTMLInputElement>(null)

    useEffect(() => {
        if (ref.current) ref.current.indeterminate = indeterminate
    }, [indeterminate])

    return (
        <input
            ref={ref}
            type="checkbox"
            aria-label={label}
            checked={checked}
            onChange={(event) => onChange(event.target.checked)}
            className={cn('kit-checkbox m-0 align-middle', className)}
        />
    )
}
