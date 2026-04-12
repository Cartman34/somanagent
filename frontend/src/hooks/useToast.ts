/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useCallback } from 'react'
import { useToastStore } from '@/lib/toast'
import type { ToastType } from '@/lib/toast'

export interface ToastHelpers {
  success: (message: string, channel?: string) => void
  error: (message: string, channel?: string) => void
  info: (message: string, channel?: string) => void
}

export interface UseToastReturn {
  toast: ToastHelpers
}

/**
 * Provides toast notification helpers to a React component.
 */
export function useToast(): UseToastReturn {
  const addToast = useToastStore((s) => s.addToast)

  const make = useCallback(
    (type: ToastType) =>
      (message: string, channel?: string) =>
        addToast(message, type, channel),
    [addToast],
  )

  return {
    toast: {
      success: make('success'),
      error: make('error'),
      info: make('info'),
    },
  }
}
