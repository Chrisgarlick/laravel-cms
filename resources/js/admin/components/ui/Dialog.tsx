import { Dialog as RadixDialog } from 'radix-ui'
import { X } from 'lucide-react'
import type { ReactNode } from 'react'
import { cn } from './cn'

interface DialogProps {
    open: boolean
    onOpenChange: (open: boolean) => void
    title: ReactNode
    description?: ReactNode
    children: ReactNode
    footer?: ReactNode
    className?: string
    /** "sheet" slides in from the right, for side panels like revisions. */
    variant?: 'center' | 'sheet'
}

/** A modal dialog or right-hand sheet, with Radix doing focus trap and Escape. */
export function Dialog({ open, onOpenChange, title, description, children, footer, className, variant = 'center' }: DialogProps) {
    return (
        <RadixDialog.Root open={open} onOpenChange={onOpenChange}>
            <RadixDialog.Portal>
                <RadixDialog.Overlay className="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] animate-in" />
                <RadixDialog.Content
                    className={cn(
                        'fixed z-50 flex flex-col border border-line bg-elevated shadow-lg outline-none',
                        variant === 'center'
                            ? 'top-[12vh] left-1/2 max-h-[76vh] w-[min(560px,calc(100vw-32px))] -translate-x-1/2 rounded-[var(--radius-dialog)] animate-pop'
                            : 'top-0 right-0 bottom-0 w-[min(480px,100vw)] animate-slide-in',
                        className,
                    )}
                >
                    <div className="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
                        <div>
                            <RadixDialog.Title className="text-[15px] font-semibold">{title}</RadixDialog.Title>
                            {description ? (
                                <RadixDialog.Description className="mt-0.5 text-[13px] text-fg-muted">{description}</RadixDialog.Description>
                            ) : (
                                <RadixDialog.Description className="sr-only">{title}</RadixDialog.Description>
                            )}
                        </div>
                        <RadixDialog.Close className="-mr-1 rounded-md p-1 text-fg-subtle hover:bg-hover hover:text-fg" aria-label="Close">
                            <X className="size-4" />
                        </RadixDialog.Close>
                    </div>
                    <div className="flex-1 overflow-y-auto px-5 py-4">{children}</div>
                    {footer && <div className="flex justify-end gap-2 border-t border-line px-5 py-3">{footer}</div>}
                </RadixDialog.Content>
            </RadixDialog.Portal>
        </RadixDialog.Root>
    )
}
