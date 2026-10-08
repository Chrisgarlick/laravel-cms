import type { PageProps as InertiaPageProps } from '@inertiajs/core'
import type { ComponentType, ReactNode } from 'react'

/**
 * Props every admin page receives, shared from the server.
 *
 * Kept in step with Cg\Cms\Http\Middleware\HandleAdminInertiaRequests::share().
 * There is no codegen between the two, so the honest position is that this type
 * is a claim about the server rather than a guarantee, and the place to look
 * when a prop is undefined at runtime is that middleware.
 */
export interface SharedProps extends InertiaPageProps {
    cms: {
        site: string
        prefix: string
        version: string
        collections: Array<{ handle: string; label: string }>
        media: { maxKb: number; accept: string[] }
    }
    nav: {
        collections: Record<string, { total: number; drafts: number }>
        leads: number
        leadsAttention: boolean
        seoBlocking: number
        queue: { pending: number; failed: number; stalled: boolean }
        mediaMissingAlt: number
        /** Advisories against production dependencies, from the last check. */
        security: number
    } | null
    auth: {
        user: { id: number; name: string; email: string } | null
    }
    flash: {
        success: string | null
        error: string | null
    }
}

/** A page component, optionally declaring its own layout. */
export type AdminPage<P = Record<string, unknown>> = ComponentType<P> & {
    layout?: ((page: ReactNode) => ReactNode) | null
}

export interface PageModule {
    default: AdminPage
}
