/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { create } from 'zustand'

export type ToastType = 'success' | 'error' | 'info'

export interface ToastItem {
  id: string
  message: string
  type: ToastType
  channel?: string
}

interface ToastState {
  toasts: ToastItem[]
  addToast: (message: string, type: ToastType, channel?: string) => void
  removeToast: (id: string) => void
}

/**
 * Global toast notification store.
 * When a channel is provided, any existing toast on that channel is replaced by the new one.
 */
export const useToastStore = create<ToastState>((set) => ({
  toasts: [],

  addToast: (message, type, channel) => set((state) => {
    const id = `toast-${Date.now()}-${Math.random().toString(36).slice(2)}`
    const newToast: ToastItem = { id, message, type, channel }

    if (channel !== undefined) {
      const filtered = state.toasts.filter((t) => t.channel !== channel)
      return { toasts: [...filtered, newToast] }
    }

    return { toasts: [...state.toasts, newToast] }
  }),

  removeToast: (id) => set((state) => ({
    toasts: state.toasts.filter((t) => t.id !== id),
  })),
}))
