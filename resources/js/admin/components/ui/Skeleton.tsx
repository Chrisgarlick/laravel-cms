import { cn } from './cn'

/** kit's skeleton; size it with utilities (h-4 w-20) like the content it stands in for. */
export function Skeleton({ className }: { className?: string }) {
    return <span className={cn('kit-skeleton', className)} aria-hidden />
}
