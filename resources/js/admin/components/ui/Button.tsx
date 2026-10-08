import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react'
import { Loader2 } from 'lucide-react'
import { cn } from './cn'

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'subtle'
type Size = 'sm' | 'md' | 'icon' | 'icon-sm'

const variants: Record<Variant, string> = {
    primary: 'bg-accent text-on-accent shadow-sm hover:bg-accent-hover active:translate-y-px',
    secondary: 'bg-panel text-fg border border-line shadow-sm hover:bg-hover hover:border-line-strong',
    ghost: 'text-fg-muted hover:bg-hover hover:text-fg',
    subtle: 'bg-sunken text-fg hover:bg-hover',
    danger: 'bg-danger-soft text-danger hover:bg-danger hover:text-white',
}

const sizes: Record<Size, string> = {
    sm: 'h-7 px-2.5 text-[12.5px] gap-1.5',
    md: 'h-8 px-3 text-[13px] gap-2',
    icon: 'h-8 w-8 justify-center',
    'icon-sm': 'h-7 w-7 justify-center',
}

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: Variant
    size?: Size
    loading?: boolean
    icon?: ReactNode
}

/**
 * The one button. Five variants, one primary per screen (the plan's "calm"
 * rule): primary is Ember, everything else stays neutral.
 */
export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
    { variant = 'secondary', size = 'md', loading = false, icon, className, children, disabled, type = 'button', ...props },
    ref,
) {
    return (
        <button
            ref={ref}
            type={type}
            disabled={disabled || loading}
            className={cn(
                'inline-flex shrink-0 select-none items-center rounded-[var(--radius-control)] font-medium whitespace-nowrap',
                'transition-[background-color,border-color,color,transform] duration-150 ease-[var(--ease-snap)]',
                'disabled:pointer-events-none disabled:opacity-50',
                variants[variant],
                sizes[size],
                className,
            )}
            {...props}
        >
            {loading ? <Loader2 className="size-3.5 animate-spin" aria-hidden /> : icon}
            {children}
        </button>
    )
})
