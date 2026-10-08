import { forwardRef, type InputHTMLAttributes, type TextareaHTMLAttributes } from 'react'
import { cn } from './cn'

const base =
    'w-full rounded-[var(--radius-control)] border border-line bg-panel px-2.5 text-[13.5px] text-fg shadow-sm ' +
    'placeholder:text-fg-subtle transition-[border-color,box-shadow] duration-150 ' +
    'hover:border-line-strong focus:border-accent focus:outline-none focus:ring-3 focus:ring-[var(--accent-ring)] ' +
    'disabled:opacity-60 aria-invalid:border-danger'

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(function Input(
    { className, ...props },
    ref,
) {
    return <input ref={ref} className={cn(base, 'h-8', className)} {...props} />
})

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement>>(function Textarea(
    { className, ...props },
    ref,
) {
    return <textarea ref={ref} className={cn(base, 'min-h-20 py-2 leading-relaxed', className)} {...props} />
})
