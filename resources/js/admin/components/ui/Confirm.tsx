import type { ReactNode } from 'react'
import { Button } from './Button'
import { Dialog } from './Dialog'

/** "Are you sure?", styled, replacing window.confirm. */
export function Confirm({ open, onOpenChange, title, description, confirmLabel = 'Confirm', danger = false, onConfirm }: { open: boolean; onOpenChange: (open: boolean) => void; title: string; description?: ReactNode; confirmLabel?: string; danger?: boolean; onConfirm: () => void }) {
    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title={title}
            size="sm"
            footer={
                <>
                    <Button onClick={() => onOpenChange(false)}>Cancel</Button>
                    <Button
                        variant={danger ? 'danger' : 'primary'}
                        onClick={() => {
                            onOpenChange(false)
                            onConfirm()
                        }}
                    >
                        {confirmLabel}
                    </Button>
                </>
            }
        >
            {description}
        </Dialog>
    )
}
