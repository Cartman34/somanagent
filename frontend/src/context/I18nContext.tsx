/**
 * Central i18n context and provider for the frontend.
 *
 * Exposes a single source of truth for the current locale and a shared
 * translation cache backed by React Query.  Components should never call
 * `translationsApi.list()` directly — they must go through `useI18n()` or
 * `useTranslation()`.
 *
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { createContext, useContext, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { translationsApi } from '@/api/translations'

// ─── Locale state ─────────────────────────────────────────────────────────────

let _globalLocale = 'fr'

/**
 * Return the current application locale as returned by the backend.
 *
 * Updated automatically whenever a translation query resolves.
 */
export function getLocale(): string {
  return _globalLocale
}

// ─── Context ──────────────────────────────────────────────────────────────────

interface I18nContextValue {
  locale: string
}

const I18nContext = createContext<I18nContextValue | null>(null)

/**
 * Access the global i18n context.
 *
 * Must be used inside an `<I18nProvider>`.  Returns `{ locale }`.
 */
export function useI18n(): I18nContextValue {
  const ctx = useContext(I18nContext)
  if (!ctx) {
    throw new Error('useI18n() must be used within an <I18nProvider>')
  }
  return ctx
}

// ─── Provider ─────────────────────────────────────────────────────────────────

/**
 * Bootstrap provider that performs a single "locale probe" query to obtain the
 * current locale from the backend.  Place it high in the tree (inside
 * `main.tsx`).
 */
export function I18nProvider({ children }: { children: ReactNode }) {
  useQuery({
    queryKey: ['ui-translations', '__locale_probe__'],
    queryFn: async () => {
      const data = await translationsApi.list(['common.action.refresh'])
      _globalLocale = data.locale
      return data
    },
    staleTime: Infinity,
    retry: false,
  })

  return (
    <I18nContext.Provider value={{ locale: _globalLocale }}>
      {children}
    </I18nContext.Provider>
  )
}
