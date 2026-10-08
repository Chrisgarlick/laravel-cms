import { Toast as RadixToast } from 'radix-ui'
import { CircleAlert, CircleCheck, X } from 'lucide-react'
import { useSyncExternalStore, type ReactNode } from 'react'
import { cn } from './cn'

interface ToastItem {
    id: number
    tone: 'success' | 'error' | 'info'
    title: string
    action?: { label: string; onClick: () => void }
}

let items: ToastItem[] = []
let nextId = 1
const listeners = new Set<() => void>()

function emit(): void {
    listeners.forEach((notify) => notify())
}

function dismiss(id: number): void {
    items = items.filter((item) => item.id !== id)
    emit()
}

/**
 * Show a toast from anywhere, including outside React (an Inertia callback).
 * `action` is how undo works: the toast carries the button, the caller
 * carries the request that reverses the change.
 */
export function toast(title: string, options: { tone?: ToastItem['tone']; action?: ToastItem['action'] } = {}): void {
    items = [...items.slice(-3), { id: nextId++, tone: options.tone ?? 'success', title, action: options.action }]
    emit()
}

const icons: Record<ToastItem['tone'], ReactNode> = {
    success: <CircleCheck className="size-4 text-success" />,
    error: <CircleAlert className="size-4 text-danger" />,
    info: <CircleAlert className="size-4 text-info" />,
}

/** Mounted once in the layout. */
export function Toaster() {
    const list = useSyncExternalStore(
        (notify) => {
            listeners.add(notify)

            return () => listeners.delete(notify)
        },
        () => items,
    )

    return (
        <RadixToast.Provider duration={5000} swipeDirection="right">
            {list.map((item) => (
                <RadixToast.Root
                    key={item.id}
                    duration={item.tone === 'error' ? 8000 : 5000}
                    onOpenChange={(open) => !open && dismiss(item.id)}
                    className={cn(
                        'flex items-center gap-3 rounded-[var(--radius-card)] border border-line bg-elevated py-2.5 pr-2 pl-3.5 shadow-lg',
                        'data-[state=open]:animate-slide-in data-[swipe=move]:translate-x-[var(--radix-toast-swipe-move-x)]',
                    )}
                >
                    {icons[item.tone]}
                    <RadixToast.Title className="flex-1 text-[13px] font-medium">{item.title}</RadixToast.Title>
                    {item.action && (
                        <RadixToast.Action
                            altText={item.action.label}
                            onClick={item.action.onClick}
                            className="h-7 rounded-md px-2 text-[12.5px] font-medium text-accent hover:bg-accent-soft"
                        >
                            {item.action.label}
                        </RadixToast.Action>
                    )}
                    <RadixToast.Close className="rounded-md p-1 text-fg-subtle hover:bg-hover hover:text-fg" aria-label="Dismiss">
                        <X className="size-3.5" />
                    </RadixToast.Close>
                </RadixToast.Root>
            ))}
            <RadixToast.Viewport className="fixed right-4 bottom-4 z-[60] flex w-[min(380px,calc(100vw-32px))] flex-col gap-2 outline-none" />
        </RadixToast.Provider>
    )
}
