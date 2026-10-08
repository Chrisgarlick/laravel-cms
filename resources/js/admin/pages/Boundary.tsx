import { Head, Link } from '@inertiajs/react'
import { usePage } from '@inertiajs/react'
import type { SharedProps } from '@/types'

interface Props {
    checks: Array<{ name: string; detail: string; passed: boolean }>
}

/**
 * What the package boundary actually resolved to, at runtime.
 *
 * Reaching this page at all is the proof, because it arrives as a lazily
 * imported chunk fetched from the configured base. The table is the detail:
 * where the manifest was read from, which asset URL was generated, whether a
 * dev server is driving the page, and whether the host application's own Vite
 * manifest was touched.
 *
 * Kept after step 1 rather than deleted. It costs one small chunk in a bundle
 * no public visitor ever downloads, and the first time the admin misbehaves
 * after a deploy this is the page that says whether the published assets are
 * the ones the server is pointing at.
 */
export default function Boundary({ checks }: Props) {
    const { cms } = usePage<SharedProps>().props

    return (
        <>
            <Head title="Boundary check" />

            <h1>Boundary check</h1>

            <p className="admin-meta">
                This page was loaded as a separate chunk. Seeing it means dynamic imports
                resolve correctly against the published base.
            </p>

            <table className="admin-table">
                <tbody>
                    {checks.map((check) => (
                        <tr key={check.name}>
                            <td>{check.passed ? 'OK' : 'CHECK'}</td>
                            <td>{check.name}</td>
                            <td>
                                <code>{check.detail}</code>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>

            <p>
                <Link href={cms.prefix}>Back to the dashboard</Link>
            </p>
        </>
    )
}
