import { registeredTypes } from './registry'
import type { FieldProps } from './types'

/**
 * A field type with no component registered.
 *
 * Loud rather than absent, and it shows the value so nothing is lost while
 * somebody works out what happened. A field that silently fails to render is
 * a field whose value silently fails to save on the next submit, and the
 * entry looks fine until a page goes out with a section missing.
 */
export default function UnknownField({ field, value }: FieldProps<unknown>) {
    return (
        <div className="field-unknown">
            <p className="field-warn">
                No component is registered for field type <code>{field.type}</code>. Register
                one with <code>registerField(&apos;{field.type}&apos;, Component)</code>.
            </p>

            <p className="field-help">Registered types: {registeredTypes().join(', ')}</p>

            <pre className="field-raw">{JSON.stringify(value, null, 2)}</pre>
        </div>
    )
}
