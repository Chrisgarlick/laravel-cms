import { Tooltip as RadixTooltip } from 'radix-ui'
import type { ReactNode } from 'react'

export const TooltipProvider = RadixTooltip.Provider

/** A short label on hover or focus. Wrap the app in TooltipProvider once. */
export function Tooltip({ content, side = 'bottom', children }: { content: ReactNode; side?: 'top' | 'right' | 'bottom' | 'left'; children: ReactNode }) {
    return (
        <RadixTooltip.Root>
            <RadixTooltip.Trigger asChild>{children}</RadixTooltip.Trigger>
            <RadixTooltip.Portal>
                <RadixTooltip.Content
                    side={side}
                    sideOffset={6}
                    className="z-50 rounded-md bg-fg px-2 py-1 text-[12px] font-medium text-canvas shadow-md animate-pop"
                >
                    {content}
                </RadixTooltip.Content>
            </RadixTooltip.Portal>
        </RadixTooltip.Root>
    )
}
