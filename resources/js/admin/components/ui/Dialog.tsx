import { X } from 'lucide-react'
import { useEffect, useId, useRef, type ReactNode } from 'react'
import { cn } from './cn'

interface DialogProps {
    open: boolean
    onOpenChange: (open: boolean) => void
    title: ReactNode
    description?: ReactNode
    children: ReactNode
    footer?: ReactNode
    className?: string
    /** "sheet" slides in from the right (kit's drawer), for side panels like revisions. */
    variant?: 'center' | 'sheet'
    /** kit's widths: sm 24rem, md 32rem, lg 48rem. Wider still with a `[--_w:…]` class. */
    size?: 'sm' | 'md' | 'lg'
}

/**
 * A modal dialog or right-hand sheet: kit's dialog on the native <dialog>.
 *
 * showModal() gives the focus trap, Escape, the backdrop and inert page
 * behind it, so nothing here reimplements them. The contents mount only
 * while open, as they did under Radix, so a dialog's state starts fresh.
 */
export function Dialog({ open, onOpenChange, title, description, children, footer, className, variant = 'center', size }: DialogProps) {
    const ref = useRef<HTMLDialogElement>(null)
    const id = useId()

    useEffect(() => {
        const dialog = ref.current

        if (dialog === null) return
        if (open && !dialog.open) dialog.showModal()
        if (!open && dialog.open) dialog.close()
    }, [open])

    return (
        <dialog
            ref={ref}
            className={cn('kit-dialog', variant === 'sheet' && 'kit-drawer', className)}
            data-size={size === 'md' ? undefined : size}
            aria-labelledby={`${id}-title`}
            aria-describedby={description ? `${id}-description` : undefined}
            onClose={() => open && onOpenChange(false)}
            // A click on the dialog element itself is a click on the backdrop.
            onClick={(event) => event.target === event.currentTarget && onOpenChange(false)}
        >
            {open && (
                <>
                    <header className="kit-dialog-header">
                        <div>
                            <h2 id={`${id}-title`}>{title}</h2>
                            {description && <p id={`${id}-description`} className="mt-0.5 text-fg-muted">{description}</p>}
                        </div>
                        <button type="button" className="kit-button" data-variant="ghost" data-size="sm" data-icon="" aria-label="Close" onClick={() => onOpenChange(false)}>
                            <X />
                        </button>
                    </header>
                    <div className="kit-dialog-body">{children}</div>
                    {footer && <footer className="kit-dialog-footer">{footer}</footer>}
                </>
            )}
        </dialog>
    )
}
