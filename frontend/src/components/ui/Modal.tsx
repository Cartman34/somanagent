/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useEffect, useRef } from 'react'
import { X } from 'lucide-react'
import clsx from 'clsx'

interface ModalProps {
  open: boolean
  onClose: () => void
  title: string
  children: React.ReactNode
  size?: 'sm' | 'md' | 'lg' | 'xl' | '2xl'
}

const sizes = {
  sm: 'max-w-sm',
  md: 'max-w-lg',
  lg: 'max-w-2xl',
  xl: 'max-w-5xl',
  '2xl': 'max-w-7xl',
}

/**
 * Renders a reusable modal dialog with overlay-close and Escape handling while protecting text selections from accidental close.
 */
export default function Modal({ open, onClose, title, children, size = 'md' }: ModalProps) {
  const overlayRef = useRef<HTMLDivElement>(null)
  const overlayPointerDownRef = useRef(false)

  useEffect(() => {
    const handleKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    if (open) document.addEventListener('keydown', handleKey)
    return () => document.removeEventListener('keydown', handleKey)
  }, [open, onClose])

  if (!open) return null

  return (
    <div
      ref={overlayRef}
      className="fixed inset-0 z-50 flex items-start sm:items-center justify-center overflow-y-auto p-3 sm:p-4"
      style={{ background: 'rgba(0, 0, 0, 0.58)' }}
      onMouseDown={(e) => {
        overlayPointerDownRef.current = e.target === overlayRef.current
      }}
      onClick={(e) => {
        const shouldClose = overlayPointerDownRef.current && e.target === overlayRef.current
        overlayPointerDownRef.current = false

        if (shouldClose) {
          onClose()
        }
      }}
    >
      <div
        className={clsx('my-3 sm:my-4 flex w-full flex-col overflow-hidden', sizes[size])}
        style={{
          background: 'var(--surface)',
          color: 'var(--text)',
          border: '1px solid var(--border)',
          borderRadius: 'var(--radius-card, var(--radius))',
          boxShadow: 'var(--shadow)',
          maxHeight: 'min(94vh, 980px)',
        }}
      >
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b" style={{ borderColor: 'var(--border)' }}>
          <h2 className="text-lg font-semibold" style={{ color: 'var(--text)' }}>{title}</h2>
          <button
            onClick={onClose}
            className="transition-colors"
            style={{ color: 'var(--muted)' }}
          >
            <X className="w-5 h-5" />
          </button>
        </div>
        {/* Body */}
        <div className="min-h-0 flex-1 overflow-y-auto overflow-x-hidden px-4 py-4 sm:px-6">{children}</div>
      </div>
    </div>
  )
}
