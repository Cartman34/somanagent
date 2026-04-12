/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useEffect, useRef } from 'react'
import { X, CheckCircle, AlertCircle, Info } from 'lucide-react'
import { useToastStore } from '@/lib/toast'
import type { ToastItem } from '@/lib/toast'
import { useTranslation } from '@/hooks/useTranslation'

const TOAST_TRANSLATION_KEYS = ['toast.dismiss'] as const

const AUTO_DISMISS_MS = 4000

const TYPE_STYLES: Record<ToastItem['type'], { background: string; color: string; border: string }> = {
  success: {
    background: 'rgba(34,197,94,0.1)',
    color: '#16a34a',
    border: '1px solid rgba(34,197,94,0.3)',
  },
  error: {
    background: 'rgba(239,68,68,0.1)',
    color: '#dc2626',
    border: '1px solid rgba(239,68,68,0.3)',
  },
  info: {
    background: 'rgba(59,130,246,0.1)',
    color: '#2563eb',
    border: '1px solid rgba(59,130,246,0.3)',
  },
}

const TYPE_ICONS = {
  success: CheckCircle,
  error: AlertCircle,
  info: Info,
}

/**
 * Individual toast notification with icon, dismiss button and auto-dismiss for success type.
 */
function ToastItemComponent({ toast, onDismiss }: { toast: ToastItem; onDismiss: () => void }) {
  const { t } = useTranslation(TOAST_TRANSLATION_KEYS)
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    if (toast.type !== 'success') return
    timerRef.current = setTimeout(onDismiss, AUTO_DISMISS_MS)
    return () => {
      if (timerRef.current !== null) clearTimeout(timerRef.current)
    }
  }, [toast.type, onDismiss])

  const styles = TYPE_STYLES[toast.type]
  const Icon = TYPE_ICONS[toast.type]

  return (
    <div
      role="alert"
      className="flex items-start gap-3 px-4 py-3 rounded shadow-lg text-sm max-w-sm w-full"
      style={{ ...styles, background: styles.background }}
    >
      <Icon className="w-4 h-4 mt-0.5 shrink-0" />
      <span className="flex-1">{toast.message}</span>
      <button
        onClick={onDismiss}
        className="shrink-0 opacity-60 hover:opacity-100 transition-opacity"
        aria-label={t('toast.dismiss')}
      >
        <X className="w-4 h-4" />
      </button>
    </div>
  )
}

/**
 * Fixed overlay rendering the list of active toast notifications.
 */
export function ToastContainer() {
  const { toasts, removeToast } = useToastStore()

  if (toasts.length === 0) return null

  return (
    <div className="fixed bottom-6 right-6 z-50 flex flex-col gap-2 items-end">
      {toasts.map((toast) => (
        <ToastItemComponent
          key={toast.id}
          toast={toast}
          onDismiss={() => removeToast(toast.id)}
        />
      ))}
    </div>
  )
}
