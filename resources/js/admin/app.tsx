import { createInertiaApp } from '@inertiajs/react'
import { createRoot } from 'react-dom/client'
import Layout from '@/layouts/Layout'
import type { PageModule } from '@/types'
import '@/styles/app.css'

/**
 * The admin SPA.
 *
 * Mounted at whatever prefix the package is configured with, and completely
 * separate from the host application's Inertia setup: its own root view, its
 * own asset version, its own page resolution. Two Inertia apps on one domain,
 * one per URL prefix, sharing nothing.
 *
 * Pages resolve from a glob rooted in this package. That is deliberate rather
 * than incidental: if page resolution went through the host app's `pages/`
 * directory, an application could shadow an admin screen by creating a file
 * with the right name, and the extension point would be a path convention
 * instead of the explicit field and block registries.
 */

const pages = import.meta.glob<PageModule>('./pages/**/*.tsx')

void createInertiaApp({
    // Read from the root view rather than hardcoded, so the prefix stays
    // configurable in one place: config('cg-cms.admin.prefix').
    title: (title) => {
        const site = document.documentElement.dataset.cgSite ?? 'CMS'

        return title ? `${title} | ${site}` : site
    },

    resolve: async (name) => {
        const page = pages[`./pages/${name}.tsx`]

        if (page === undefined) {
            // Loud, and with the list. A silently blank screen is the single
            // most expensive failure mode in an SPA admin, because it looks
            // identical to a server error, a routing mistake and a build that
            // did not include the page.
            throw new Error(
                `Unknown admin page [${name}]. Known pages: ${Object.keys(pages)
                    .map((path) => path.replace('./pages/', '').replace('.tsx', ''))
                    .join(', ')}`,
            )
        }

        const module = await page()

        // A page may opt out by exporting its own layout, including `null`.
        module.default.layout ??= (content) => <Layout>{content}</Layout>

        return module
    },

    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />)
    },

    progress: {
        // The admin runs on the same box as the site. A visible progress bar
        // is how an editor tells "saving" from "nothing happened".
        color: '#ff5a1f',
    },
})
