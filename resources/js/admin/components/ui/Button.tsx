import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react'
import { cn } from './cn'

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'subtle'
type Size = 'sm' | 'md' | 'icon' | 'icon-sm'

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: Variant
    size?: Size
    loading?: boolean
    icon?: ReactNode
}

/**
 * The one button: kit's (`kit-button`), so it looks like a button in Clients,
 * Motion and Typeset and changes when kit does. One primary per screen;
 * everything else stays secondary or ghost. `subtle` is kept as a name for
 * ghost. `loading` is kit's aria-busy spinner, which keeps focus.
 */
export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
    { variant = 'secondary', size = 'md', loading = false, icon, className, children, disabled, type = 'button', ...props },
    ref,
) {
    return (
        <button
            ref={ref}
            type={type}
            disabled={disabled}
            aria-busy={loading || undefined}
            data-variant={variant === 'subtle' ? 'ghost' : variant === 'secondary' ? undefined : variant}
            data-size={size === 'sm' || size === 'icon-sm' ? 'sm' : undefined}
            data-icon={size === 'icon' || size === 'icon-sm' ? '' : undefined}
            className={cn('kit-button', className)}
            {...props}
        >
            {!loading && icon}
            {children}
        </button>
    )
})
