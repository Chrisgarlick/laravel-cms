import { Loader2, Monitor, Smartphone } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { cn } from './ui'

interface Block {
    type: string
    data: Record<string, unknown>
}

/**
 * Blocks rendered by the site's own templates and CSS, as you edit.
 *
 * The server renders, so the preview cannot drift from the page. It sits in
 * an iframe with no script permissions: the markup is the site's, but there
 * is no reason to run anything inside the admin. The frame grows to its
 * content, and can be narrowed to phone width.
 */
export function BlockPreviewFrame({ blocks, prefix, className }: { blocks: Block[]; prefix: string; className?: string }) {
    const settled = useDebouncedValue(JSON.stringify(blocks), 400)
    const [html, setHtml] = useState<string | null>(null)
    const [loading, setLoading] = useState(false)
    const [failed, setFailed] = useState(false)
    const [width, setWidth] = useState<'desktop' | 'mobile'>('desktop')
    const [height, setHeight] = useState(240)
    const frame = useRef<HTMLIFrameElement>(null)

    useEffect(() => {
        const controller = new AbortController()
        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
        setLoading(true)

        fetch(`${prefix}/blocks/preview`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'text/html', 'X-CSRF-TOKEN': token },
            body: JSON.stringify({ blocks: JSON.parse(settled) }),
            signal: controller.signal,
        })
            .then((response) => (response.ok ? response.text() : Promise.reject(new Error(String(response.status)))))
            .then((body) => {
                setHtml(body)
                setFailed(false)
            })
            .catch((error: unknown) => {
                if (!(error instanceof DOMException && error.name === 'AbortError')) setFailed(true)
            })
            .finally(() => setLoading(false))

        return () => controller.abort()
    }, [settled, prefix])

    // Fit the frame to what it contains, including after fonts and images load.
    const fit = () => {
        const body = frame.current?.contentDocument?.body

        if (body) setHeight(Math.max(120, body.scrollHeight))
    }

    useEffect(() => {
        const timer = setInterval(fit, 500)

        return () => clearInterval(timer)
    }, [html])

    return (
        <div className={cn('overflow-hidden rounded-[var(--radius-card)] border border-line bg-sunken', className)}>
            <div className="flex items-center justify-between border-b border-line bg-panel px-3 py-1.5">
                <span className="flex items-center gap-1.5 text-[12px] text-fg-subtle">
                    {loading ? <Loader2 className="size-3 animate-spin" /> : <span className="size-1.5 rounded-full bg-success" />}
                    {failed ? 'Preview failed to render' : 'Live preview'}
                </span>
                <div className="flex rounded-[5px] bg-sunken p-0.5">
                    {(['desktop', 'mobile'] as const).map((mode) => (
                        <button
                            key={mode}
                            type="button"
                            onClick={() => setWidth(mode)}
                            aria-pressed={width === mode}
                            aria-label={`${mode} width`}
                            className={cn('inline-flex size-6 items-center justify-center rounded-[4px]', width === mode ? 'bg-panel text-fg shadow-sm' : 'text-fg-subtle hover:text-fg')}
                        >
                            {mode === 'desktop' ? <Monitor className="size-3.5" /> : <Smartphone className="size-3.5" />}
                        </button>
                    ))}
                </div>
            </div>
            <div className="flex justify-center p-3">
                {html !== null ? (
                    <iframe
                        ref={frame}
                        title="Block preview"
                        srcDoc={html}
                        sandbox="allow-same-origin"
                        onLoad={fit}
                        className={cn('rounded-md border border-line bg-white shadow-sm transition-[width] duration-300', width === 'desktop' ? 'w-full' : 'w-[390px]')}
                        style={{ height }}
                    />
                ) : (
                    <div className="flex h-40 w-full items-center justify-center text-[12.5px] text-fg-subtle">{failed ? 'Could not render this block.' : 'Rendering…'}</div>
                )}
            </div>
        </div>
    )
}
