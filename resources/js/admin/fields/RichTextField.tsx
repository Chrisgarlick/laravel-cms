import { lazy, Suspense } from 'react'
import type { FieldProps } from './types'

/**
 * Rich text, with TipTap behind a lazy boundary.
 *
 * TipTap is by a distance the heaviest dependency in the admin, and most
 * screens have no rich text field at all: a collection index, the redirects
 * table and the media library would all be paying for an editor they never
 * show. Loading it as a separate chunk keeps the initial bundle small enough
 * to be irrelevant on a mobile connection, which is what makes fixing a typo
 * from a phone realistic.
 *
 * This wrapper stays tiny on purpose. Everything that imports TipTap lives in
 * the lazily loaded component, because an import here would pull the whole
 * library back into the main bundle and silently undo the split.
 */
const Editor = lazy(() => import('../components/TiptapEditor'))

export default function RichTextField({ field, value, onChange }: FieldProps<unknown>) {
    return (
        <Suspense fallback={<div className="field-loading">Loading the editor</div>}>
            <Editor field={field} value={value} onChange={onChange} />
        </Suspense>
    )
}
