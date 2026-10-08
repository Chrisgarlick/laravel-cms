import { DropdownMenu } from 'radix-ui'
import type { ComponentProps, ReactNode } from 'react'
import { cn } from './cn'

/**
 * Dropdown menus, on Radix for keyboard support, focus return and collision
 * handling. Styled once here; screens only compose.
 */
export const Menu = DropdownMenu.Root
export const MenuTrigger = DropdownMenu.Trigger

export function MenuContent({ className, align = 'end', sideOffset = 6, ...props }: ComponentProps<typeof DropdownMenu.Content>) {
    return (
        <DropdownMenu.Portal>
            <DropdownMenu.Content
                align={align}
                sideOffset={sideOffset}
                className={cn(
                    'z-50 min-w-48 rounded-[var(--radius-card)] border border-line bg-elevated p-1 shadow-lg animate-pop',
                    className,
                )}
                {...props}
            />
        </DropdownMenu.Portal>
    )
}

export function MenuItem({ className, icon, shortcut, danger, children, ...props }: ComponentProps<typeof DropdownMenu.Item> & { icon?: ReactNode; shortcut?: string; danger?: boolean }) {
    return (
        <DropdownMenu.Item
            className={cn(
                'flex h-8 cursor-default select-none items-center gap-2.5 rounded-[5px] px-2 text-[13px] outline-none',
                'data-[highlighted]:bg-hover data-[disabled]:opacity-50 [&_svg]:size-4 [&_svg]:text-fg-subtle',
                danger ? 'text-danger [&_svg]:text-danger' : 'text-fg',
                className,
            )}
            {...props}
        >
            {icon}
            <span className="flex-1">{children}</span>
            {shortcut && <span className="text-[11px] text-fg-subtle">{shortcut}</span>}
        </DropdownMenu.Item>
    )
}

export function MenuLabel({ children }: { children: ReactNode }) {
    return <DropdownMenu.Label className="px-2 pt-1.5 pb-1 text-[11px] font-medium text-fg-subtle">{children}</DropdownMenu.Label>
}

export function MenuSeparator() {
    return <DropdownMenu.Separator className="-mx-1 my-1 h-px bg-line" />
}

export const MenuRadioGroup = DropdownMenu.RadioGroup

export function MenuRadioItem({ className, icon, children, ...props }: ComponentProps<typeof DropdownMenu.RadioItem> & { icon?: ReactNode }) {
    return (
        <DropdownMenu.RadioItem
            className={cn(
                'flex h-8 cursor-default select-none items-center gap-2.5 rounded-[5px] px-2 text-[13px] text-fg outline-none',
                'data-[highlighted]:bg-hover data-[state=checked]:text-accent [&_svg]:size-4',
                className,
            )}
            {...props}
        >
            {icon}
            <span className="flex-1">{children}</span>
            <DropdownMenu.ItemIndicator className="size-1.5 rounded-full bg-accent" />
        </DropdownMenu.RadioItem>
    )
}
