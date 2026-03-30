/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useState, useRef, useEffect } from 'react'
import { Palette } from 'lucide-react'
import { useTheme, THEME_META } from '@/hooks/useTheme'
import type { Theme } from '@/hooks/useTheme'

export default function ThemeSwitcher() {
  const { theme, setTheme, themes } = useTheme()
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  // Close on outside click
  useEffect(() => {
    if (!open) return
    const handler = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [open])

  const pick = (t: Theme) => {
    setTheme(t)
    setOpen(false)
  }

  return (
    <div ref={ref} style={{ position: 'relative' }}>
      <button
        onClick={() => setOpen((v) => !v)}
        title={`Theme: ${THEME_META[theme].label}`}
        style={{
          display: 'inline-flex',
          alignItems: 'center',
          gap: '6px',
          padding: '3px 8px',
          borderRadius: 'var(--radius)',
          border: '1px solid var(--border)',
          background: 'transparent',
          color: 'var(--muted)',
          cursor: 'pointer',
          fontSize: '11px',
          fontFamily: 'inherit',
          transition: 'border-color 0.15s, color 0.15s',
        }}
        onMouseEnter={(e) => {
          ;(e.currentTarget as HTMLElement).style.color = 'var(--text)'
          ;(e.currentTarget as HTMLElement).style.borderColor = 'var(--brand)'
        }}
        onMouseLeave={(e) => {
          ;(e.currentTarget as HTMLElement).style.color = 'var(--muted)'
          ;(e.currentTarget as HTMLElement).style.borderColor = 'var(--border)'
        }}
      >
        {/* Active theme accent dot */}
        <span
          style={{
            width: '8px',
            height: '8px',
            borderRadius: '50%',
            background: THEME_META[theme].accent,
            flexShrink: 0,
            boxShadow: `0 0 6px ${THEME_META[theme].accent}80`,
          }}
        />
        <Palette size={12} />
      </button>

      {open && (
        <div
          style={{
            position: 'absolute',
            bottom: 'calc(100% + 6px)',
            left: 0,
            minWidth: '140px',
            background: 'var(--surface)',
            border: '1px solid var(--border)',
            borderRadius: 'var(--radius-card, var(--radius))',
            boxShadow: 'var(--shadow)',
            padding: '4px',
            zIndex: 50,
          }}
        >
          {themes.map((t) => {
            const meta = THEME_META[t]
            const isActive = t === theme
            return (
              <button
                key={t}
                onClick={() => pick(t)}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: '10px',
                  width: '100%',
                  padding: '6px 10px',
                  borderRadius: 'var(--radius)',
                  border: 'none',
                  background: isActive ? 'var(--brand-dim)' : 'transparent',
                  color: isActive ? 'var(--brand)' : 'var(--muted)',
                  cursor: 'pointer',
                  fontSize: '12px',
                  fontFamily: 'inherit',
                  fontWeight: isActive ? 600 : 400,
                  textAlign: 'left',
                  transition: 'background 0.12s, color 0.12s',
                }}
                onMouseEnter={(e) => {
                  if (!isActive) {
                    ;(e.currentTarget as HTMLElement).style.background = 'var(--surface2)'
                    ;(e.currentTarget as HTMLElement).style.color = 'var(--text)'
                  }
                }}
                onMouseLeave={(e) => {
                  if (!isActive) {
                    ;(e.currentTarget as HTMLElement).style.background = 'transparent'
                    ;(e.currentTarget as HTMLElement).style.color = 'var(--muted)'
                  }
                }}
              >
                <span
                  style={{
                    width: '10px',
                    height: '10px',
                    borderRadius: '50%',
                    background: meta.accent,
                    flexShrink: 0,
                    boxShadow: isActive ? `0 0 6px ${meta.accent}` : 'none',
                  }}
                />
                {meta.label}
              </button>
            )
          })}
        </div>
      )}
    </div>
  )
}
