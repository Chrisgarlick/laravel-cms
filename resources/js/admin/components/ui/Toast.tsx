import { Toast as RadixToast } from 'radix-ui'
import { CircleAlert, CircleCheck, X } from 'lucide-react'
import { useSyncExternalStore, type ReactNode } from 'react'

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

// kit's toast colours the icon by tone.
const icons: Record<ToastItem['tone'], ReactNode> = {
    success: <CircleCheck />,
    error: <CircleAlert />,
    info: <CircleAlert />,
}

const tones: Record<ToastItem['tone'], string> = { success: 'good', error: 'bad', info: 'info' }

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
                    className="kit-toast data-[swipe=move]:translate-x-[var(--radix-toast-swipe-move-x)]"
                    data-tone={tones[item.tone]}
                >
                    {icons[item.tone]}
                    <div className="kit-toast-body">
                        <RadixToast.Title asChild>
                            <p className="kit-toast-title">{item.title}</p>
                        </RadixToast.Title>
                    </div>
                    {item.action && (
                        <RadixToast.Action asChild altText={item.action.label} onClick={item.action.onClick}>
                            <button type="button" className="kit-button" data-variant="ghost" data-size="sm">{item.action.label}</button>
                        </RadixToast.Action>
                    )}
                    <RadixToast.Close className="kit-toast-close" aria-label="Dismiss">
                        <X />
                    </RadixToast.Close>
                </RadixToast.Root>
            ))}
            <RadixToast.Viewport className="kit-toasts" data-open="" />
        </RadixToast.Provider>
    )
}
