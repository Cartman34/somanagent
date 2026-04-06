/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import clsx from 'clsx'

interface SpinnerProps {
  size?: 'sm' | 'md' | 'lg'
  className?: string
}

const sizes = {
  sm: 'w-4 h-4',
  md: 'w-6 h-6',
  lg: 'w-10 h-10',
}

/** Reusable loading spinner with size variants for inline loading states. */
export default function Spinner({ size = 'md', className }: SpinnerProps) {
  return (
    <div
      className={clsx(
        'animate-spin rounded-full border-2 border-gray-200 border-t-brand-600',
        sizes[size],
        className
      )}
      role="status"
      aria-label="Loading"
    />
  )
}

/** Full-page centered spinner for page-level loading states. */
export function PageSpinner() {
  return (
    <div className="flex items-center justify-center h-64">
      <Spinner size="lg" />
    </div>
  )
}
