import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { createHash } from 'node:crypto'
import { mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import { defineConfig, type Plugin, type ViteDevServer } from 'vite'

/**
 * The admin bundle, built entirely inside the package.
 *
 * The host application never sees any of this. It has no Node dependency, no
 * Vite plugin and no config change: `composer require`, then
 * `php artisan vendor:publish --tag=cg-cms-assets`, and the admin works. That
 * is the whole reason `dist/` is committed.
 *
 * Deliberately not using laravel-vite-plugin. It derives `base` from its
 * `buildDirectory` option and derives the output directory from that plus
 * `publicDirectory`, so getting a base of `/vendor/cg-cms/` out of it means
 * building into a directory named `vendor/`, which inside a Composer package is
 * the one directory name that cannot be used. The two things the plugin would
 * otherwise provide, a hot file and a manifest at a predictable path, are
 * fifteen lines below.
 */

/** Where the built bundle lands. Committed, and published to public/. */
const OUT_DIR = 'dist'

/**
 * Public base for every asset URL in the bundle.
 *
 * This has to match where `vendor:publish` puts the files, because dynamic
 * imports resolve against it at runtime. Page components are loaded lazily by
 * design, so a wrong base here is not a broken stylesheet, it is every screen
 * past the first one 404ing.
 */
const BASE = '/vendor/cg-cms/'

/** Laravel's Vite class reads this to know a dev server is running. */
const HOT_FILE = resolve(import.meta.dirname, OUT_DIR, 'hot')

/**
 * Write the hot file while the dev server runs, and remove it when it stops.
 *
 * A hot file left behind after Ctrl-C points the admin at a dev server that is
 * no longer listening, and the failure looks like a broken build rather than a
 * stale file. Cleanup is registered for both a clean close and a signal.
 */
function hotFile(): Plugin {
    const remove = () => rmSync(HOT_FILE, { force: true })

    return {
        name: 'cg-cms-hot-file',
        apply: 'serve',
        configureServer(server: ViteDevServer) {
            server.httpServer?.once('listening', () => {
                const address = server.httpServer?.address()

                if (address === null || typeof address !== 'object') {
                    return
                }

                const host = address.family === 'IPv6' ? `[${address.address}]` : address.address
                const origin = `http://${host === '::' || host === '0.0.0.0' ? 'localhost' : host}:${address.port}`

                mkdirSync(dirname(HOT_FILE), { recursive: true })
                writeFileSync(HOT_FILE, origin)
            })

            for (const signal of ['SIGINT', 'SIGTERM', 'SIGHUP'] as const) {
                process.once(signal, () => {
                    remove()
                    process.exit()
                })
            }

            process.on('exit', remove)
        },
        closeBundle: remove,
    }
}

/** Every source file the bundle is built from. */
const SOURCE_DIR = resolve(import.meta.dirname, 'resources/js/admin')

/**
 * A content hash of the admin sources, so staleness is checkable.
 *
 * Committing `dist/` buys an installation with no Node dependency and costs a
 * discipline requirement: rebuild before tagging, or ship a bundle that does
 * not match the code. Timestamps cannot enforce that, because git does not
 * preserve mtimes and a fresh clone would look stale on every file. A hash of
 * the source contents is the same in every checkout, so CI can fail on it.
 *
 * Read back by `php artisan cms:admin-assets --check`.
 */
function sourceHash(): Plugin {
    const hashSources = (): string => {
        const files: string[] = []

        const walk = (dir: string): void => {
            for (const item of readdirSync(dir, { withFileTypes: true })) {
                const path = join(dir, item.name)

                item.isDirectory() ? walk(path) : files.push(path)
            }
        }

        walk(SOURCE_DIR)

        /*
         * Collect first, then sort by relative path.
         *
         * Not sorted per directory while walking, because that orders a
         * directory named `pages` before a file named `pages.ts`, while a
         * byte-wise sort of the full relative paths puts `pages.ts` first
         * ('.' is 0x2E, '/' is 0x2F). The PHP side has to reproduce this
         * digest exactly, and the two would silently disagree the first time
         * such a pair of names existed, leaving the check reporting a stale
         * bundle forever.
         */
        files.sort((a, b) => {
            const left = relative(SOURCE_DIR, a)
            const right = relative(SOURCE_DIR, b)

            return left < right ? -1 : left > right ? 1 : 0
        })

        const hash = createHash('sha256')

        for (const file of files) {
            // Path as well as contents, so a rename is a change.
            hash.update(relative(SOURCE_DIR, file))
            hash.update(readFileSync(file))
        }

        return hash.digest('hex')
    }

    return {
        name: 'cg-cms-source-hash',
        apply: 'build',
        closeBundle() {
            writeFileSync(resolve(import.meta.dirname, OUT_DIR, '.sources'), hashSources())
        },
    }
}

export default defineConfig({
    base: BASE,

    plugins: [react(), tailwindcss(), hotFile(), sourceHash()],

    resolve: {
        alias: {
            '@': resolve(import.meta.dirname, 'resources/js/admin'),
        },
    },

    build: {
        outDir: OUT_DIR,
        emptyOutDir: true,

        // Laravel's Vite class looks for the manifest at
        // {buildDirectory}/{manifestFilename}. Naming it here rather than
        // letting Vite bury it in .vite/ keeps AdminVite's configuration to
        // three calls with no path surprises.
        manifest: 'manifest.json',

        rollupOptions: {
            input: resolve(import.meta.dirname, 'resources/js/admin/app.tsx'),
        },

        // The admin is never served to a public visitor, so it sits outside
        // every performance budget in section 10 of the plan. Warning about
        // chunk size here would be noise: TipTap alone will exceed it, and it
        // is a lazy chunk on the pages that need it.
        chunkSizeWarningLimit: 2000,
    },

    server: {
        // Away from 5173, so the site's own Vite and the admin's can run at
        // the same time without either noticing.
        port: 5174,
        strictPort: true,
        cors: true,
    },
})
