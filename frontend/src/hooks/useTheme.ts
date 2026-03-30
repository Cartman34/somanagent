/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useState, useEffect } from 'react'

export const THEMES = ['terminal', 'slate', 'obsidian', 'aurora', 'neo', 'chalk'] as const
export type Theme = (typeof THEMES)[number]

export const THEME_META: Record<Theme, { label: string; accent: string }> = {
  terminal: { label: 'Terminal', accent: '#4ade80' },
  slate:    { label: 'Slate',    accent: '#4f46e5' },
  obsidian: { label: 'Obsidian', accent: '#8b5cf6' },
  aurora:   { label: 'Aurora',   accent: '#a855f7' },
  neo:      { label: 'Neo',      accent: '#00f5ff' },
  chalk:    { label: 'Chalk',    accent: '#c2780a' },
}

const STORAGE_KEY = 'sma-theme'
const DEFAULT_THEME: Theme = 'terminal'

function applyTheme(theme: Theme) {
  const root = document.documentElement
  if (theme === DEFAULT_THEME) {
    root.removeAttribute('data-theme')
  } else {
    root.setAttribute('data-theme', theme)
  }
}

export function useTheme() {
  const [theme, setThemeState] = useState<Theme>(() => {
    const saved = localStorage.getItem(STORAGE_KEY) as Theme | null
    return saved && (THEMES as readonly string[]).includes(saved) ? saved : DEFAULT_THEME
  })

  useEffect(() => {
    applyTheme(theme)
    localStorage.setItem(STORAGE_KEY, theme)
  }, [theme])

  const setTheme = (next: Theme) => setThemeState(next)

  return { theme, setTheme, themes: THEMES, meta: THEME_META }
}
