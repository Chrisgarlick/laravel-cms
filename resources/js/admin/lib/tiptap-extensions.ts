import StarterKit from '@tiptap/starter-kit'
import type { Editor, Extensions, JSONContent } from '@tiptap/core'

/**
 * The editor's extension set, in one place.
 *
 * Extracted from the component so the Node smoke test can construct an editor
 * with exactly what ships, rather than a copy that drifts. The bug that
 * prompted it was a duplicate extension registration, which is invisible to
 * `tsc`, invisible to the bundler, and fatal at mount.
 *
 * Link is configured **through StarterKit**, not added alongside it.
 * StarterKit has bundled Link since v3, so importing `@tiptap/extension-link`
 * and passing it as a second extension registers the name twice: TipTap warns
 * about the duplicate and the editor then fails to finish constructing, which
 * surfaces as a blank admin and `Cannot read properties of null (reading
 * 'commands')`. The lesson generalises, which is why the smoke test asserts
 * no name appears twice rather than asserting this one case.
 */
export function adminExtensions(): Extensions {
    return [
        StarterKit.configure({
            // An entry never emits its own h1. The page title is the h1, and
            // two of them is an SEO problem. The renderer clamps to 2 as well,
            // so a document that somehow contains one still renders correctly;
            // this stops the button existing.
            heading: { levels: [2, 3, 4] },

            link: {
                openOnClick: false,
                autolink: false,

                // The renderer decides rel="noopener" by comparing the link's
                // host against the site domain. Setting it here as well would
                // put that decision in two places, and they would disagree.
                HTMLAttributes: {},
            },
        }),
    ]
}

/**
 * Replace the editor's document when the value changes from outside.
 *
 * A function rather than inline code in the effect, so the guard below can be
 * tested without mounting React.
 *
 * **The guard is the point.** `Editor.commands` is
 * `return this.commandManager.commands` with no null check, unlike `chain()`
 * and `can()` which both guard, and `destroy()` sets `commandManager` to null.
 * So touching `commands` on a destroyed editor throws
 * "Cannot read properties of null (reading 'commands')", which took down the
 * whole admin: React has no error boundary here, so a throw in an effect
 * unmounts the tree and leaves a blank page.
 *
 * Reaching a destroyed editor is not exotic. `EditorInstanceManager` schedules
 * a destroy on a 1ms timer from its own constructor, and only cancels it once
 * the component's effects have run. This editor arrives as a lazily imported
 * chunk, so the gap between render and effects is routinely longer than a
 * millisecond: the editor is destroyed, `setEditor(null)` is queued, and an
 * effect in the same commit still holds the destroyed instance. Non-null and
 * unusable is the state that has to be handled.
 *
 * Returns whether it did anything, which is what the smoke test asserts on.
 */
export function syncEditorContent(editor: Editor | null, value: unknown): boolean {
    if (editor === null || editor.isDestroyed || !isTiptapDocument(value)) {
        return false
    }

    // Compared before setting, because setContent on every render moves the
    // caret to the start of the document on every keystroke.
    if (JSON.stringify(editor.getJSON()) === JSON.stringify(value)) {
        return false
    }

    editor.commands.setContent(value, { emitUpdate: false })

    return true
}

export function isTiptapDocument(value: unknown): value is JSONContent {
    return typeof value === 'object' && value !== null && (value as { type?: string }).type === 'doc'
}

export function emptyTiptapDocument(): JSONContent {
    return { type: 'doc', content: [{ type: 'paragraph' }] }
}
