import type { FieldComponent } from './types'
import BlocksField from './BlocksField'
import BooleanField from './BooleanField'
import DatetimeField from './DatetimeField'
import GroupField from './GroupField'
import MediaField from './MediaField'
import NumberField from './NumberField'
import RelationField from './RelationField'
import RepeaterField from './RepeaterField'
import RichTextField from './RichTextField'
import SelectField from './SelectField'
import SeoField from './SeoField'
import SlugField from './SlugField'
import TextField from './TextField'
import TextareaField from './TextareaField'
import UnknownField from './UnknownField'

/**
 * One React component per field type, resolved by type string.
 *
 * This is the load-bearing abstraction section 5.12 describes, and the reason
 * every screen after the entry editor is cheaper: adding a field type is one
 * PHP class and one component registered here, with no change to any screen
 * that might display it.
 *
 * The old admin had a hand-written form per collection, which is why adding a
 * field there meant editing the CMS.
 */
const registry = new Map<string, FieldComponent>([
    ['text', TextField],
    ['textarea', TextareaField],
    ['slug', SlugField],
    ['richText', RichTextField],
    ['number', NumberField],
    ['url', TextField],
    ['datetime', DatetimeField],
    ['select', SelectField],
    ['boolean', BooleanField],
    ['relation', RelationField],
    ['seo', SeoField],
    ['media', MediaField],
    ['blocks', BlocksField],
    ['repeater', RepeaterField],
    ['group', GroupField],
])

/**
 * Register a field type, or replace a built-in.
 *
 * The extension point section 5.13 promises. An application adds its own
 * component here and a matching `Field` class in PHP, and nothing in the
 * package needs to know about either.
 */
export function registerField(type: string, component: FieldComponent): void {
    registry.set(type, component)
}

/**
 * Resolve a component for a type.
 *
 * Never returns undefined. A missing component renders as a visible, named
 * problem rather than as a gap in the form: a field that silently fails to
 * render is a field whose value silently fails to save, and the entry looks
 * fine until somebody notices the data is gone.
 */
export function resolveField(type: string): FieldComponent {
    return registry.get(type) ?? UnknownField
}

export function registeredTypes(): string[] {
    return [...registry.keys()].sort()
}
