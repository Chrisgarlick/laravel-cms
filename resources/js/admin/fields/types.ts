import type { ComponentType } from 'react'

/**
 * A field as the server describes it.
 *
 * Mirrors Cg\Cms\Schema\Field::toArray() plus the two keys SchemaSerializer
 * adds. Every property here is a decision made in PHP: the client renders what
 * it is told and decides nothing about what a field means.
 */
export interface FieldSchema {
    name: string
    type: string
    label: string
    help: string | null
    required: boolean
    nullable: boolean
    maxLength: number | null
    slugSource: string | null
    default: string | null
    options: string[]
    lint: string[]
    relatedCollection: string | null
    many: boolean
    requireAlt: boolean
    preset: string | null

    /** Blocks: the layouts this field allows, each with its own field schema. */
    allowedBlocks: Array<{ handle: string; label: string; fields: FieldSchema[] }>

    /** Repeater: the fields each row holds, and how many rows are allowed. */
    fields?: FieldSchema[]
    min?: number | null
    max?: number | null
    rowLabel?: string | null

    /** Editor placement: the tab it sits on (null is "Content"), and whether it lives in the inspector. */
    tab?: string | null
    sidebar?: boolean

    /** Only shown while a sibling field holds one of these values. */
    condition?: { field: string; values: Array<string | number | boolean> } | null

    /** True when the value lives in its own column rather than in `data`. */
    native: boolean

    /** Relation fields only: what can be chosen. */
    choices?: Array<{ value: string; label: string }>

    /** Slug fields only: the route pattern, for showing the resulting URL. */
    preview?: string | null
}

export interface FieldProps<T = unknown> {
    field: FieldSchema
    value: T
    onChange: (value: T) => void
    error?: string

    /**
     * Every other value on the form.
     *
     * Needed by the fields that derive from another: a slug watches the title.
     * Read-only by convention; a field that wants to change another does it
     * through its own onChange and lets the parent decide.
     */
    values: Record<string, unknown>
}

/**
 * What the registry stores.
 *
 * The value type is deliberately loose. A registry keyed by a runtime string
 * holds components with genuinely different value types (a string for text, a
 * document for rich text, an array for relations), and no single parameter can
 * describe all of them: `unknown` makes every component's own `onChange`
 * unassignable, and `never` fails the same way in the other direction.
 *
 * The type safety that matters is inside each component, where the concrete
 * type is declared. This one line is where it is knowingly given up, which is
 * better than spreading casts through every field.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export type FieldComponent = ComponentType<FieldProps<any>>
