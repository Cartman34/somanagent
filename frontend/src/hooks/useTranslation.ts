/**
 * Centralised translation hook backed by a shared React Query cache.
 *
 * Usage convention in every component:
 *
 *   1. Declare a `const XXX_TRANSLATION_KEYS = [...] as const` at module level.
 *   2. Call `const { t, locale, isLoading } = useTranslation(XXX_TRANSLATION_KEYS)`.
 *   3. Never call `translationsApi.list()` directly in UI code.
 *   4. Never define a local `tt()` / `t()` wrapper.
 *
 * The React Query cache key is normalised to `['ui-translations', domain, sortedKeys]`
 * so that identical requests across components share a single cache entry.
 *
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { translationsApi } from '@/api/translations'
import { getLocale } from '@/context/I18nContext'

/**
 * Standard API returned by `useTranslation()`.
 */
export interface UseTranslationReturn {
  /** Resolve a translation key to its translated value (falls back to the key). Supports `%param%` interpolation via the `params` argument. */
  t: (key: string, params?: Record<string, string>) => string
  /** Current application locale (e.g. `'fr'`). */
  locale: string
  /** Whether the initial translation fetch is still in flight. */
  isLoading: boolean
  /** Format a date (short). */
  formatDate: (value: string) => string
  /** Format a date + time (short). */
  formatDateTime: (value: string) => string
  /** Format a time (HH:mm). */
  formatTime: (value: string) => string
  /** Format a number. */
  formatNumber: (value: number) => string
}

/**
 * Fetch translations for the given keys and domain, returning a standard i18n API.
 *
 * @param keys - `as const` array of dot-notation translation keys.
 * @param domain - Translation domain (maps to `<domain>.fr.yaml`). Defaults to `'app'`.
 */
export function useTranslation(
  keys: readonly string[],
  domain = 'app',
): UseTranslationReturn {
  const sortedKeys = useMemo(() => [...keys].sort(), [keys])

  const { data, isLoading } = useQuery({
    queryKey: ['ui-translations', domain, sortedKeys],
    queryFn: () => translationsApi.list(sortedKeys, domain),
    staleTime: Infinity,
  })

  const translations = data?.translations ?? {}
  const locale = data?.locale ?? getLocale()

  const t = (key: string, params?: Record<string, string>): string => {
    const value = translations[key] ?? key
    if (!params) return value
    return Object.entries(params).reduce(
      (result, [param, replacement]) => result.replace(`%${param}%`, replacement),
      value,
    )
  }

  const formatDate = (value: string) =>
    new Intl.DateTimeFormat(locale, { dateStyle: 'short' }).format(new Date(value))

  const formatDateTime = (value: string) =>
    new Intl.DateTimeFormat(locale, { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))

  const formatTime = (value: string) =>
    new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit' }).format(new Date(value))

  const formatNumber = (value: number) =>
    new Intl.NumberFormat(locale).format(value)

  return { t, locale, isLoading, formatDate, formatDateTime, formatTime, formatNumber }
}
