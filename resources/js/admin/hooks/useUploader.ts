import { useCallback, useRef, useState } from 'react'

export interface MediaItem {
    id: string
    filename: string
    alt: string | null
    mime: string
    width: number
    height: number
    bytes: number
    focalX: number
    focalY: number
    thumb: string
    preview: string
    processed: boolean
    createdAt: string | null
    usage?: number | null
}

export interface Upload {
    key: string
    name: string
    progress: number
    state: 'queued' | 'uploading' | 'done' | 'existing' | 'failed'
    error: string | null
    media: MediaItem | null
}

interface Options {
    url: string
    maxKb: number
    onUploaded?: (media: MediaItem) => void
    onFinished?: () => void
}

/**
 * Uploads, one file at a time, each with its own progress.
 *
 * XMLHttpRequest rather than fetch, because fetch still cannot report upload
 * progress, and a drag of twelve photos with one spinner for all of them gives
 * no way to tell a slow upload from a stuck one.
 *
 * Sequential on purpose. Parallel uploads to a single vCPU just make every
 * file slower, and they finish in an order that has nothing to do with the
 * order they were dropped in.
 *
 * A file over the size limit is refused here, before a byte is sent, with the
 * same limit the server enforces: otherwise the first anybody hears of it is a
 * 413 after a twenty-megabyte upload.
 */
export function useUploader({ url, maxKb, onUploaded, onFinished }: Options) {
    const [uploads, setUploads] = useState<Upload[]>([])
    const queue = useRef<Array<{ key: string; file: File }>>([])
    const running = useRef(false)

    const update = (key: string, patch: Partial<Upload>): void =>
        setUploads((current) => current.map((upload) => (upload.key === key ? { ...upload, ...patch } : upload)))

    const next = useCallback((): void => {
        const job = queue.current.shift()

        if (job === undefined) {
            running.current = false
            onFinished?.()

            return
        }

        running.current = true
        update(job.key, { state: 'uploading' })

        const body = new FormData()
        body.append('file', job.file)

        const request = new XMLHttpRequest()
        request.open('POST', url)
        request.setRequestHeader('Accept', 'application/json')
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest')
        request.setRequestHeader(
            'X-CSRF-TOKEN',
            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
        )

        request.upload.onprogress = (event) => {
            if (event.lengthComputable) {
                update(job.key, { progress: Math.round((event.loaded / event.total) * 100) })
            }
        }

        request.onload = () => {
            let payload: unknown = null

            try {
                payload = JSON.parse(request.responseText)
            } catch {
                // A non-JSON body is an HTML error page, reported below.
            }

            if (request.status === 200 || request.status === 201) {
                const media = payload as MediaItem
                update(job.key, { state: request.status === 201 ? 'done' : 'existing', progress: 100, media })
                onUploaded?.(media)
            } else {
                update(job.key, { state: 'failed', error: errorFrom(request.status, payload) })
            }

            next()
        }

        request.onerror = () => {
            update(job.key, { state: 'failed', error: 'The connection dropped.' })
            next()
        }

        request.send(body)
    }, [url, onUploaded, onFinished])

    const add = useCallback(
        (files: FileList | File[]): void => {
            const added: Upload[] = []

            for (const file of Array.from(files)) {
                const key = `${file.name}-${file.size}-${Math.random().toString(36).slice(2)}`
                const tooBig = file.size > maxKb * 1024

                added.push({
                    key,
                    name: file.name,
                    progress: 0,
                    state: tooBig ? 'failed' : 'queued',
                    error: tooBig ? `Larger than ${Math.round(maxKb / 1024)} MB.` : null,
                    media: null,
                })

                if (!tooBig) {
                    queue.current.push({ key, file })
                }
            }

            setUploads((current) => [...added, ...current])

            if (!running.current) {
                next()
            }
        },
        [maxKb, next],
    )

    const clear = (): void => setUploads((current) => current.filter((upload) => upload.state === 'uploading' || upload.state === 'queued'))

    return { uploads, add, clear, busy: uploads.some((upload) => upload.state === 'uploading' || upload.state === 'queued') }
}

function errorFrom(status: number, payload: unknown): string {
    if (status === 413) {
        return 'Larger than the server accepts.'
    }

    if (status === 419) {
        return 'The session expired. Reload the page and try again.'
    }

    const body = payload as { message?: string; errors?: Record<string, string[]> } | null
    const first = body?.errors !== undefined ? Object.values(body.errors)[0]?.[0] : undefined

    return first ?? body?.message ?? `Upload failed (${status}).`
}
