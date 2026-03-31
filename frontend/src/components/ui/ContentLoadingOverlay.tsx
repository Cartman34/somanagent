/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { Loader2 } from 'lucide-react'

interface ContentLoadingOverlayProps {
  isLoading: boolean
  label: string
  className?: string
}

/**
 * Displays a semi-transparent overlay with a loading indicator
 * over the parent container when isLoading is true.
 * Uses theme-aware CSS variables for colors.
 */
export default function ContentLoadingOverlay({
  isLoading,
  label,
  className = '',
}: ContentLoadingOverlayProps) {
  if( !isLoading ) return null

  return (
    <div
      className={`absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 rounded-lg ${className}`}
      style={{
        background: 'color-mix(in srgb, var(--surface) 85%, transparent)',
        backdropFilter: 'blur(2px)',
      }}
    >
      <Loader2 className="w-6 h-6 animate-spin" style={{ color: 'var(--brand)' }} />
      <span className="text-sm font-medium" style={{ color: 'var(--muted)' }}>
        {label}
      </span>
    </div>
  )
}
