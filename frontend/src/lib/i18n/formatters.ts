/**
 * Formatting helpers driven by the global locale.
 *
 * All date/time/number formatting in the UI should go through these helpers
 * instead of hard-coding `'fr-FR'`.
 *
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { getLocale } from '@/context/I18nContext'

/**
 * Format a date string using the current locale (short date + short time).
 */
export function formatDate(value: string): string {
  return new Intl.DateTimeFormat(getLocale(), { dateStyle: 'short' }).format(new Date(value))
}

/**
 * Format a date string with short date and short time.
 */
export function formatDateTime(value: string): string {
  return new Intl.DateTimeFormat(getLocale(), { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
}

/**
 * Format a time string (hour + minute).
 */
export function formatTime(value: string): string {
  return new Intl.DateTimeFormat(getLocale(), { hour: '2-digit', minute: '2-digit' }).format(new Date(value))
}

/**
 * Format a number using the current locale.
 */
export function formatNumber(value: number): string {
  return new Intl.NumberFormat(getLocale()).format(value)
}
