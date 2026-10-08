import { usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import MediaPicker from '@/components/MediaPicker'
import type { MediaItem } from '@/hooks/useUploader'
import type { SharedProps } from '@/types'
import type { FieldProps } from './types'

/**
 * What a media field stores.
 *
 * `id` is a library uuid. `src` is the older shape, a path with no library row
 * behind it, still written by the Phase 6 import for images that predate the
 * library. A value has one or the other.
 */
interface MediaValue {
    id?: string
    src?: string
    alt?: string
}

/**
 * An image from the library, plus what it means on this entry.
 *
 * The library holds an image's default alt text; this field holds the alt
 * text for this use of it, because the same photograph can be "the office" on
 * one page and "where the workshop was held" on another. Choosing an image
 * copies the library's alt in as a starting point, which is all most uses
 * need, and the field stays editable for the ones that need more.
 */
export default function MediaField({ field, value, onChange }: FieldProps<MediaValue | null>) {
    const { cms } = usePage<SharedProps>().props
    const media = value ?? {}
    const [picking, setPicking] = useState(false)
    const [item, setItem] = useState<MediaItem | null>(null)
    const [missing, setMissing] = useState(false)

    // The stored value is a uuid, so the thumbnail has to be looked up. Only
    // when the uuid changes, and only when we do not already have the row
    // from the picker.
    useEffect(() => {
        if (media.id === undefined) {
            setItem(null)
            setMissing(false)

            return
        }

        if (item?.id === media.id) {
            return
        }

        let cancelled = false

        void fetch(`${cms.prefix}/media/browse?ids[]=${encodeURIComponent(media.id)}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
            .then((body: { data: MediaItem[] }) => {
                if (!cancelled) {
                    setItem(body.data[0] ?? null)
                    setMissing(body.data.length === 0)
                }
            })
            .catch(() => undefined)

        return () => {
            cancelled = true
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [media.id, cms.prefix])

    const pick = (picked: MediaItem): void => {
        setItem(picked)
        setMissing(false)

        // Keep alt text already written for this use; otherwise start from
        // the library's.
        onChange({ id: picked.id, alt: media.alt || picked.alt || '' })
    }

    const setAlt = (alt: string): void => onChange({ ...media, alt })

    const hasImage = Boolean(media.id || media.src)
    const thumb = item?.thumb ?? media.src

    return (
        // The id is what the editor's error summary scrolls to. Without it an
        // error on `cover_image.alt` names the field and then cannot find it.
        <div className="field-media" id={`field-${field.name}`}>
            {hasImage ? (
                <div className="field-media-chosen">
                    {thumb !== undefined && !missing && (
                        <img className="field-preview" src={thumb} alt="" width={120} height={120} />
                    )}

                    <div>
                        <p className="field-mono">
                            {item?.filename ?? media.src ?? 'Loading'}
                            {media.src !== undefined && media.id === undefined && (
                                <span className="pill"> not in the library</span>
                            )}
                        </p>

                        {missing && (
                            <p className="field-warn">
                                This image has been deleted from the library. Choose another, or
                                remove it.
                            </p>
                        )}

                        <div className="editor-actions">
                            <button type="button" className="button-quiet" onClick={() => setPicking(true)}>
                                Replace
                            </button>
                            <button type="button" className="button-quiet" onClick={() => onChange(null)}>
                                Remove
                            </button>
                        </div>
                    </div>
                </div>
            ) : (
                <button type="button" className="button-quiet" onClick={() => setPicking(true)}>
                    Choose an image
                </button>
            )}

            {hasImage && (
                <label className="field-sub">
                    Alt text{field.requireAlt && <span className="field-required"> required</span>}
                    <input
                        type="text"
                        className="field-input"
                        value={media.alt ?? ''}
                        maxLength={255}
                        required={field.requireAlt}
                        onChange={(event) => setAlt(event.target.value)}
                    />
                </label>
            )}

            {field.requireAlt && hasImage && !media.alt && (
                <p className="field-warn">
                    This field requires alt text. Describe what the image shows, not that it is an
                    image.
                </p>
            )}

            {field.preset !== null && (
                <p className="field-help">Shown as the {field.preset} crop. Set the focal point in the library.</p>
            )}

            <MediaPicker open={picking} onClose={() => setPicking(false)} onPick={pick} />
        </div>
    )
}
