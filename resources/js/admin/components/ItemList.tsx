import {
    DndContext,
    KeyboardSensor,
    PointerSensor,
    closestCenter,
    useSensor,
    useSensors,
    type DragEndEvent,
} from '@dnd-kit/core'
import { restrictToParentElement, restrictToVerticalAxis } from '@dnd-kit/modifiers'
import {
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import { useCallback, useId, useState, type ReactNode } from 'react'

export interface Item {
    /**
     * A stable identity for the row, independent of its position.
     *
     * Not the array index. dnd-kit keys its sortable items by this, and React
     * keys the rows by it: with an index, reordering two rows swaps their
     * keys, so React reuses the wrong DOM nodes and every collapsed state,
     * text cursor and half-typed input follows the wrong row.
     *
     * Assigned client-side and never persisted, because it means nothing to
     * the server: order is the array order.
     */
    _key: string
}

interface Props<T extends Item> {
    items: T[]
    onChange: (items: T[]) => void

    /** The row body. Given the item and its index. */
    children: (item: T, index: number) => ReactNode

    /** One line identifying the row when collapsed. */
    summary: (item: T, index: number) => ReactNode

    /** Adding. Omit to make the list fixed-length. */
    onAdd?: () => T | null
    addLabel?: string

    min?: number | null
    max?: number | null
    disabled?: boolean
}

/**
 * An ordered list of editable rows: add, remove, reorder, collapse, duplicate.
 *
 * One component, used by both the block canvas and the repeater field. They
 * look like different features and are the same problem: an ordered list of
 * typed rows whose bodies render nested fields. The only difference is whether
 * the rows can be of more than one type, and that is a decision the caller
 * makes in `onAdd` and `summary`.
 *
 * Building them separately would have meant two implementations of drag
 * ordering, two of collapse state, and two places for the identity bug in
 * `Item._key` above to be got wrong.
 */
export default function ItemList<T extends Item>({
    items,
    onChange,
    children,
    summary,
    onAdd,
    addLabel = 'Add',
    min = null,
    max = null,
    disabled = false,
}: Props<T>) {
    const [collapsed, setCollapsed] = useState<Set<string>>(new Set())
    const contextId = useId()

    const sensors = useSensors(
        // A small distance before a drag starts, so clicking a button inside a
        // row is a click rather than a one-pixel drag that swallows it.
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
    )

    const atMin = min !== null && items.length <= min
    const atMax = max !== null && items.length >= max

    const move = useCallback(
        (event: DragEndEvent): void => {
            const { active, over } = event

            if (over === null || active.id === over.id) {
                return
            }

            const from = items.findIndex((item) => item._key === active.id)
            const to = items.findIndex((item) => item._key === over.id)

            if (from === -1 || to === -1) {
                return
            }

            const next = [...items]
            const [moved] = next.splice(from, 1)

            if (moved !== undefined) {
                next.splice(to, 0, moved)
                onChange(next)
            }
        },
        [items, onChange],
    )

    const toggle = (key: string): void =>
        setCollapsed((current) => {
            const next = new Set(current)

            next.has(key) ? next.delete(key) : next.add(key)

            return next
        })

    return (
        <div className="itemlist">
            <DndContext
                id={contextId}
                sensors={sensors}
                collisionDetection={closestCenter}
                // Rows only move up and down, and never outside the list.
                // Without these a dragged block can be dropped across the page
                // and the drop target is anyone's guess.
                modifiers={[restrictToVerticalAxis, restrictToParentElement]}
                onDragEnd={move}
            >
                <SortableContext
                    items={items.map((item) => item._key)}
                    strategy={verticalListSortingStrategy}
                >
                    <ol className="itemlist-rows">
                        {items.map((item, index) => (
                            <Row
                                key={item._key}
                                id={item._key}
                                collapsed={collapsed.has(item._key)}
                                summary={summary(item, index)}
                                position={index + 1}
                                disabled={disabled}
                                onToggle={() => toggle(item._key)}
                                onDuplicate={
                                    atMax
                                        ? undefined
                                        : () => {
                                              const copy = {
                                                  ...structuredClone(item),
                                                  _key: newKey(),
                                              }
                                              const next = [...items]

                                              next.splice(index + 1, 0, copy)
                                              onChange(next)
                                          }
                                }
                                onRemove={
                                    atMin
                                        ? undefined
                                        : () => {
                                              if (
                                                  window.confirm(
                                                      'Remove this? It goes when you save.',
                                                  )
                                              ) {
                                                  onChange(items.filter((_, i) => i !== index))
                                              }
                                          }
                                }
                            >
                                {children(item, index)}
                            </Row>
                        ))}
                    </ol>
                </SortableContext>
            </DndContext>

            {items.length === 0 && <p className="field-help">Nothing here yet.</p>}

            {onAdd !== undefined && (
                <button
                    type="button"
                    className="button-quiet"
                    disabled={atMax || disabled}
                    onClick={() => {
                        const item = onAdd()

                        if (item !== null) {
                            onChange([...items, item])
                        }
                    }}
                >
                    {addLabel}
                </button>
            )}

            {atMax && <p className="field-help">Maximum of {max} reached.</p>}
        </div>
    )
}

interface RowProps {
    id: string
    position: number
    collapsed: boolean
    summary: ReactNode
    disabled: boolean
    onToggle: () => void
    onRemove?: () => void
    onDuplicate?: () => void
    children: ReactNode
}

function Row({
    id,
    position,
    collapsed,
    summary,
    disabled,
    onToggle,
    onRemove,
    onDuplicate,
    children,
}: RowProps) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
        id,
        disabled,
    })

    return (
        <li
            ref={setNodeRef}
            className={`itemlist-row${isDragging ? ' is-dragging' : ''}`}
            style={{ transform: CSS.Transform.toString(transform), transition }}
        >
            <div className="itemlist-head">
                {/*
                    A dedicated handle rather than dragging by the whole row.
                    A row contains text inputs, and making the row itself
                    draggable means selecting a word inside one starts a drag.
                */}
                <button
                    type="button"
                    className="itemlist-handle"
                    aria-label={`Reorder item ${position}`}
                    {...attributes}
                    {...listeners}
                >
                    ⠿
                </button>

                <button type="button" className="itemlist-summary" onClick={onToggle}>
                    <span className="itemlist-position">{position}</span>
                    {summary}
                </button>

                <span className="itemlist-actions">
                    <button type="button" className="button-quiet" onClick={onToggle}>
                        {collapsed ? 'Open' : 'Close'}
                    </button>

                    {onDuplicate !== undefined && (
                        <button type="button" className="button-quiet" onClick={onDuplicate}>
                            Duplicate
                        </button>
                    )}

                    {onRemove !== undefined && (
                        <button type="button" className="button-quiet" onClick={onRemove}>
                            Remove
                        </button>
                    )}
                </span>
            </div>

            {/*
                Hidden rather than unmounted.

                Unmounting a collapsed row throws away the state inside it, and
                for a rich text field that means tearing down and rebuilding a
                TipTap instance every time somebody collapses a block. It also
                loses the caret. `hidden` keeps the DOM and costs nothing.
            */}
            <div className="itemlist-body" hidden={collapsed}>
                {children}
            </div>
        </li>
    )
}

/**
 * A client-side row identity.
 *
 * Never persisted. It exists only so React and dnd-kit can tell rows apart
 * across a reorder; the server cares about array order and nothing else.
 */
export function newKey(): string {
    return `row-${Math.random().toString(36).slice(2, 10)}`
}

/**
 * Give stored rows their client-side keys.
 *
 * Content arrives from the server as plain objects with no identity, so this
 * runs once when the field mounts.
 */
export function withKeys<T extends object>(rows: unknown): Array<T & Item> {
    if (!Array.isArray(rows)) {
        return []
    }

    return rows
        .filter((row): row is T => typeof row === 'object' && row !== null)
        .map((row) => ({ ...row, _key: newKey() }) as T & Item)
}

/** Strip the client-side keys before sending rows to the server. */
export function withoutKeys<T extends Item>(rows: T[]): Array<Omit<T, '_key'>> {
    return rows.map(({ _key: _discarded, ...rest }) => rest)
}
