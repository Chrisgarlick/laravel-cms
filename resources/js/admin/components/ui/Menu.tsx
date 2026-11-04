import { DropdownMenu } from 'radix-ui'
import { Check } from 'lucide-react'
import { createContext, useContext, type ComponentProps, type ReactNode } from 'react'
import { cn } from './cn'

/**
 * Dropdown menus: kit's menu list, with Radix doing the keyboard, focus
 * return and collision handling. kit styles the list's direct buttons, so
 * every item renders as one (asChild).
 */
export const Menu = DropdownMenu.Root
export const MenuTrigger = DropdownMenu.Trigger

export function MenuContent({ className, align = 'end', sideOffset = 6, ...props }: ComponentProps<typeof DropdownMenu.Content>) {
    return (
        <DropdownMenu.Portal>
            <DropdownMenu.Content align={align} sideOffset={sideOffset} className={cn('kit-menu-list', className)} data-open="" {...props} />
        </DropdownMenu.Portal>
    )
}

export function MenuItem({ icon, shortcut, danger, children, ...props }: ComponentProps<typeof DropdownMenu.Item> & { icon?: ReactNode; shortcut?: string; danger?: boolean }) {
    return (
        <DropdownMenu.Item asChild {...props}>
            <button type="button" data-tone={danger ? 'bad' : undefined}>
                {icon}
                <span className="flex-1">{children}</span>
                {shortcut && <kbd>{shortcut}</kbd>}
            </button>
        </DropdownMenu.Item>
    )
}

export function MenuLabel({ children }: { children: ReactNode }) {
    return (
        <DropdownMenu.Label asChild>
            <p className="kit-menu-label">{children}</p>
        </DropdownMenu.Label>
    )
}

export function MenuSeparator() {
    return (
        <DropdownMenu.Separator asChild>
            <hr />
        </DropdownMenu.Separator>
    )
}

/*
 * Radio items, without Radix's RadioGroup: that renders a wrapping element,
 * and kit's list styles only its direct children. The group is a context;
 * each item is a menuitemradio that reports its value.
 */
const RadioContext = createContext<{ value: string; onValueChange: (value: string) => void } | null>(null)

export function MenuRadioGroup({ value, onValueChange, children }: { value: string; onValueChange: (value: string) => void; children: ReactNode }) {
    return <RadioContext.Provider value={{ value, onValueChange }}>{children}</RadioContext.Provider>
}

export function MenuRadioItem({ value, icon, children }: { value: string; icon?: ReactNode; children: ReactNode }) {
    const group = useContext(RadioContext)
    const checked = group?.value === value

    return (
        <DropdownMenu.Item asChild onSelect={() => group?.onValueChange(value)}>
            <button type="button" role="menuitemradio" aria-checked={checked}>
                {icon}
                <span className="flex-1">{children}</span>
                {checked && <Check className="!text-accent" aria-hidden />}
            </button>
        </DropdownMenu.Item>
    )
}
