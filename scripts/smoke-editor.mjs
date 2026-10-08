/**
 * Constructs the rich text editor exactly as the admin does, in Node.
 *
 * This exists because of a bug that every other check passed: `tsc` was clean,
 * the bundle built, 193 PHP tests passed, and the editor threw on mount in a
 * browser and rendered a blank admin. Nothing in the test suite touches the
 * JavaScript, so the only thing standing between a broken editor and a
 * deployed one was somebody opening the page.
 *
 * jsdom is enough for the failure mode that matters here, which is the editor
 * refusing to construct: extension conflicts, bad configuration, a schema that
 * ProseMirror rejects. It cannot test dragging a block, and it is not meant to.
 *
 * Deliberately imports the same extension configuration the component uses, by
 * reading it from one exported function, so this cannot drift from what ships.
 */
import { JSDOM } from 'jsdom'

const dom = new JSDOM('<!doctype html><html><body><div id="app"></div></body></html>', {
    pretendToBeVisual: true,
    url: 'http://localhost/',
})

// ProseMirror reaches for these at module scope, so they have to exist before
// TipTap is imported.
//
// Defined rather than assigned: Node ships a read-only `navigator` global, so
// a plain assignment throws. defineProperty replaces the descriptor instead.
for (const [name, value] of Object.entries({
    window: dom.window,
    document: dom.window.document,
    navigator: dom.window.navigator,
    HTMLElement: dom.window.HTMLElement,
    Element: dom.window.Element,
    Node: dom.window.Node,
    DOMParser: dom.window.DOMParser,
    MutationObserver: dom.window.MutationObserver,
    getComputedStyle: dom.window.getComputedStyle,
})) {
    Object.defineProperty(globalThis, name, { value, writable: true, configurable: true })
}

const { Editor } = await import('@tiptap/core')
const { adminExtensions, syncEditorContent } = await import(
    '../resources/js/admin/lib/tiptap-extensions.ts'
)

const failures = []

function check(name, run) {
    try {
        run()
        console.log(`  ok    ${name}`)
    } catch (error) {
        failures.push(name)
        console.log(`  FAIL  ${name}`)
        console.log(`        ${error instanceof Error ? error.message : String(error)}`)
    }
}

console.log('Rich text editor smoke test')

let editor

check('the editor constructs with the admin extension set', () => {
    editor = new Editor({
        element: document.querySelector('#app'),
        extensions: adminExtensions(),
        content: { type: 'doc', content: [{ type: 'paragraph' }] },
    })

    if (editor.commands === undefined || editor.commands === null) {
        throw new Error('editor.commands is not available after construction')
    }
})

check('TipTap reports no warnings while constructing', () => {
    // Captures TipTap's own warnings rather than introspecting the extension
    // list. The first version of this check walked
    // `StarterKit.options.extensions` looking for repeated names and passed
    // happily while TipTap was printing "Duplicate extension names found:
    // ['link']" two lines above it, because StarterKit does not expose its
    // children there. Asking the library what it thinks is wrong catches
    // every class of misconfiguration, not the one that was anticipated.
    const warnings = []
    const original = console.warn

    console.warn = (...args) => warnings.push(args.join(' '))

    try {
        new Editor({
            element: document.querySelector('#app'),
            extensions: adminExtensions(),
            content: { type: 'doc', content: [{ type: 'paragraph' }] },
        }).destroy()
    } finally {
        console.warn = original
    }

    const tiptap = warnings.filter((line) => line.includes('tiptap'))

    if (tiptap.length > 0) {
        throw new Error(tiptap.join('; '))
    }
})

check('syncing content into a destroyed editor does not throw', () => {
    /*
     * The regression that took the admin down to a blank page.
     *
     * `Editor.commands` is `this.commandManager.commands` with no null check,
     * and `destroy()` nulls `commandManager`, so touching it after destroy
     * throws. A destroyed-but-not-yet-replaced editor is a state the React
     * hook routinely hands back: it schedules a destroy on a 1ms timer from
     * its own constructor, and this editor loads as a lazy chunk, so effects
     * often commit after that timer has fired.
     *
     * There is no error boundary around the editor, so the throw unmounted
     * the tree and left a blank admin.
     */
    const doomed = new Editor({
        element: document.querySelector('#app'),
        extensions: adminExtensions(),
        content: { type: 'doc', content: [{ type: 'paragraph' }] },
    })

    doomed.destroy()

    if (doomed.commandManager !== null) {
        throw new Error('assumption broken: destroy() no longer nulls commandManager')
    }

    const changed = syncEditorContent(doomed, {
        type: 'doc',
        content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Anything.' }] }],
    })

    if (changed !== false) {
        throw new Error('expected the sync to decline a destroyed editor')
    }
})

check('syncing content into a live editor still works', () => {
    const live = new Editor({
        element: document.querySelector('#app'),
        extensions: adminExtensions(),
        content: { type: 'doc', content: [{ type: 'paragraph' }] },
    })

    const doc = {
        type: 'doc',
        content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Synced.' }] }],
    }

    if (syncEditorContent(live, doc) !== true) {
        throw new Error('expected the sync to apply')
    }

    // And declines a second time, because nothing changed. Without this the
    // caret jumps to the start of the document on every keystroke.
    if (syncEditorContent(live, doc) !== false) {
        throw new Error('expected the sync to be a no-op when the document matches')
    }

    live.destroy()
})

check('setContent replaces the document', () => {
    editor.commands.setContent(
        {
            type: 'doc',
            content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Replaced.' }] }],
        },
        { emitUpdate: false },
    )

    const text = editor.getJSON().content?.[0]?.content?.[0]?.text

    if (text !== 'Replaced.') {
        throw new Error(`expected "Replaced.", got ${JSON.stringify(text)}`)
    }
})

check('every toolbar command exists', () => {
    // The toolbar calls these by name. A renamed or missing command is a
    // button that throws when clicked, which no type check catches because
    // the chain is typed loosely.
    for (const command of [
        'toggleBold',
        'toggleItalic',
        'toggleCode',
        'toggleHeading',
        'toggleBulletList',
        'toggleOrderedList',
        'toggleBlockquote',
        'toggleCodeBlock',
        'setLink',
        'unsetLink',
    ]) {
        if (typeof editor.commands[command] !== 'function') {
            throw new Error(`missing command: ${command}`)
        }
    }
})

check('the editor offers no h1', () => {
    // An entry never emits its own h1: the page title is the h1, and two of
    // them is an SEO problem.
    //
    // Asserted on the command rather than on setContent, because `levels`
    // constrains what the editor can produce, not what a document may
    // contain: JSON passed to setContent sets attributes directly and keeps
    // a level 1. The second line of defence is TiptapRenderer, which clamps
    // to h2 on the way out and has its own test.
    if (editor.can().toggleHeading({ level: 1 })) {
        throw new Error('the editor will produce an h1')
    }

    for (const level of [2, 3, 4]) {
        if (!editor.can().toggleHeading({ level })) {
            throw new Error(`the editor will not produce an h${level}`)
        }
    }
})

editor?.destroy()

console.log(failures.length === 0 ? '\nAll checks passed.' : `\n${failures.length} check(s) failed.`)

process.exit(failures.length === 0 ? 0 : 1)
