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
            className="!w-[min(420px,calc(100vw-32px))]"
            footer={
                <>
                    <Button onClick={() => onOpenChange(false)}>Cancel</Button>
                    <Button
                        variant={danger ? 'danger' : 'primary'}
                        className={danger ? '!bg-danger !text-white hover:!opacity-90' : undefined}
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
            <div className="text-[13.5px] text-fg-muted">{description}</div>
        </Dialog>
    )
}
