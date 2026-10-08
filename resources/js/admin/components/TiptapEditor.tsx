import { EditorContent, useEditor, type Editor } from '@tiptap/react'
import { useEffect } from 'react'
import type { FieldSchema } from '../fields/types'
import {
    adminExtensions,
    emptyTiptapDocument,
    isTiptapDocument,
    syncEditorContent,
} from '../lib/tiptap-extensions'

interface Props {
    field: FieldSchema
    value: unknown
    onChange: (value: unknown) => void
}

/**
 * The rich text editor.
 *
 * Its output is a TipTap document, which is the same shape
 * Cg\Cms\Content\TiptapRenderer already turns into HTML on save. That is the
 * point: there is one document format and one renderer, so what an editor
 * types and what a visitor reads cannot drift. The old stack had a second
 * hand-written renderer for rich text inside blocks and the two produced
 * different output, which is Kritano issue 3d.
 *
 * Headings start at level 2. An entry never emits its own h1, because the page
 * title is the h1 and two of them is an SEO problem. The renderer clamps to 2
 * as well, so a document that somehow contains one still renders correctly;
 * this just stops the button existing.
 */
export default function TiptapEditor({ field, value, onChange }: Props) {
    const editor = useEditor({
        // Shared with the Node smoke test, so what is checked is what ships.
        // See lib/tiptap-extensions for why Link is configured through
        // StarterKit rather than added beside it.
        extensions: adminExtensions(),

        content: isTiptapDocument(value) ? value : emptyTiptapDocument(),

        // Tell React what changed, not what the document now renders as. HTML
        // is derived on save by the one renderer; storing it from here would
        // make the browser a second source of truth for it.
        onUpdate: ({ editor: instance }) => onChange(instance.getJSON()),

        editorProps: {
            attributes: {
                class: 'field-input field-richtext',
                id: `field-${field.name}`,
            },
        },
    })

    /*
     * Replace the content when the value changes from outside.
     *
     * Restoring a revision or recovering an autosaved draft changes the form
     * state underneath the editor, and TipTap keeps its own copy of the
     * document. Without this the fields around it would update and the body
     * would silently keep showing what was there before.
     *
     * The comparison and the destroyed-editor guard both live in
     * syncEditorContent, which the Node smoke test covers.
     */
    useEffect(() => {
        syncEditorContent(editor, value)
    }, [editor, value])

    // Destroyed as well as null. See syncEditorContent: a destroyed instance
    // is handed back for a commit or two before the hook replaces it, and
    // rendering a toolbar against one means every button reads state off an
    // editor that is on its way out.
    if (editor === null || editor.isDestroyed) {
        return <div className="field-loading">Loading the editor</div>
    }

    return (
        <div className="field-richtext-wrap">
            <Toolbar editor={editor} />
            <EditorContent editor={editor} />
        </div>
    )
}

function Toolbar({ editor }: { editor: Editor }) {
    const button = (label: string, active: boolean, action: () => void) => (
        <button
            type="button"
            className={`rt-button${active ? ' is-active' : ''}`}
            onMouseDown={(event) => {
                // Stop the editor losing focus before the command runs, which
                // is what makes a toolbar click apply to the current selection
                // rather than to nothing.
                event.preventDefault()
                action()
            }}
        >
            {label}
        </button>
    )

    return (
        <div className="rt-toolbar">
            {button('Bold', editor.isActive('bold'), () => editor.chain().focus().toggleBold().run())}
            {button('Italic', editor.isActive('italic'), () =>
                editor.chain().focus().toggleItalic().run(),
            )}
            {button('Code', editor.isActive('code'), () => editor.chain().focus().toggleCode().run())}

            {[2, 3, 4].map((level) =>
                button(`H${level}`, editor.isActive('heading', { level }), () =>
                    editor
                        .chain()
                        .focus()
                        .toggleHeading({ level: level as 2 | 3 | 4 })
                        .run(),
                ),
            )}

            {button('List', editor.isActive('bulletList'), () =>
                editor.chain().focus().toggleBulletList().run(),
            )}
            {button('Numbered', editor.isActive('orderedList'), () =>
                editor.chain().focus().toggleOrderedList().run(),
            )}
            {button('Quote', editor.isActive('blockquote'), () =>
                editor.chain().focus().toggleBlockquote().run(),
            )}
            {button('Code block', editor.isActive('codeBlock'), () =>
                editor.chain().focus().toggleCodeBlock().run(),
            )}

            {button('Link', editor.isActive('link'), () => {
                if (editor.isActive('link')) {
                    editor.chain().focus().unsetLink().run()

                    return
                }

                const href = window.prompt('Link to')

                if (href) {
                    editor.chain().focus().setLink({ href }).run()
                }
            })}
        </div>
    )
}
