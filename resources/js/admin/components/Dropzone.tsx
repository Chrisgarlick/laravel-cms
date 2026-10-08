import { useRef, useState, type DragEvent } from 'react'
import type { Upload } from '@/hooks/useUploader'

interface Props {
    accept: string[]
    uploads: Upload[]
    onFiles: (files: FileList) => void
    onClear: () => void
}

/**
 * Drop files here, or choose them.
 *
 * A real file input sits behind the drop area, so the keyboard and a screen
 * reader get an ordinary "choose files" control and dragging is the shortcut
 * rather than the only way in. On a phone, which cannot drag, that input is
 * the whole feature.
 */
export default function Dropzone({ accept, uploads, onFiles, onClear }: Props) {
    const input = useRef<HTMLInputElement>(null)
    const [over, setOver] = useState(false)

    const drop = (event: DragEvent<HTMLDivElement>): void => {
        event.preventDefault()
        setOver(false)

        if (event.dataTransfer.files.length > 0) {
            onFiles(event.dataTransfer.files)
        }
    }

    const finished = uploads.filter((upload) => upload.state !== 'queued' && upload.state !== 'uploading')

    return (
        <div className="dropzone-wrap">
            <div
                className={`dropzone${over ? ' is-over' : ''}`}
                onDragOver={(event) => {
                    event.preventDefault()
                    setOver(true)
                }}
                onDragLeave={() => setOver(false)}
                onDrop={drop}
            >
                <p>
                    Drop images here, or{' '}
                    <button type="button" className="field-link" onClick={() => input.current?.click()}>
                        choose files
                    </button>
                    .
                </p>
                <p className="panel-help">JPEG, PNG, WebP or GIF. Variants are made on the server.</p>

                <input
                    ref={input}
                    type="file"
                    multiple
                    accept={accept.join(',')}
                    className="visually-hidden"
                    onChange={(event) => {
                        if (event.target.files !== null && event.target.files.length > 0) {
                            onFiles(event.target.files)
                        }

                        // Cleared so choosing the same file again after a
                        // failure fires change rather than nothing.
                        event.target.value = ''
                    }}
                />
            </div>

            {uploads.length > 0 && (
                <ul className="upload-list" aria-live="polite">
                    {uploads.map((upload) => (
                        <li key={upload.key} className={`upload is-${upload.state}`}>
                            <span className="upload-name">{upload.name}</span>
                            <progress max={100} value={upload.progress} aria-label={`Upload of ${upload.name}`} />
                            <span className="upload-state">{describe(upload)}</span>
                        </li>
                    ))}
                </ul>
            )}

            {finished.length > 0 && (
                <button type="button" className="button-quiet" onClick={onClear}>
                    Clear finished
                </button>
            )}
        </div>
    )
}

function describe(upload: Upload): string {
    switch (upload.state) {
        case 'queued':
            return 'Waiting'
        case 'uploading':
            return `${upload.progress}%`
        case 'done':
            return 'Uploaded'
        case 'existing':
            // Same file, already in the library. Saying "uploaded" would
            // imply a second copy now exists.
            return 'Already in the library'
        case 'failed':
            return upload.error ?? 'Failed'
    }
}
