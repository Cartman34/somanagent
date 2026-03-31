/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useState } from 'react'
import { RefreshCw } from 'lucide-react'

interface PageHeaderProps {
  /** Main title displayed as h1 */
  title: string
  /** Optional subtitle below the title */
  description?: string
  /** Optional action buttons displayed on the right */
  action?: React.ReactNode
  /** Optional callback to refresh data - displays a refresh button */
  onRefresh?: () => void
  /** Optional title for the refresh button (for translations) */
  refreshTitle?: string
}

/**
 * Page header component with title, optional description, and action buttons.
 * Optionally includes a refresh button that triggers the onRefresh callback.
 */
export default function PageHeader({ title, description, action, onRefresh, refreshTitle }: PageHeaderProps) {
  return (
    <div className="flex items-start justify-between mb-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">{title}</h1>
        {description && (
          <p className="mt-1 text-sm text-gray-500">{description}</p>
        )}
      </div>
      <div className="flex-shrink-0 flex items-center gap-2">
        {onRefresh && (
          <RefreshButton onRefresh={onRefresh} title={refreshTitle} />
        )}
        {action && <div>{action}</div>}
      </div>
    </div>
  )
}

interface RefreshButtonProps {
  /** Callback to refresh data */
  onRefresh: () => void
  /** Optional title for the button (for translations) */
  title?: string
}

/**
 * Refresh button with debounce to prevent rapid re-clicks.
 * Shows a spinning icon while refresh is in progress.
 */
function RefreshButton({ onRefresh, title }: RefreshButtonProps) {
  const [isRefreshing, setIsRefreshing] = useState(false)

  const handleRefresh = () => {
    if( isRefreshing ) return
    setIsRefreshing(true)
    onRefresh()
    setTimeout(() => setIsRefreshing(false), 500)
  }

  return (
    <button
      onClick={handleRefresh}
      className="p-2 rounded hover:bg-gray-100 transition-colors"
      title={title ?? 'common.action.refresh'}
      disabled={isRefreshing}
    >
      <RefreshCw className={`w-4 h-4 ${isRefreshing ? 'animate-spin' : ''}`} style={{ color: 'var(--muted)' }} />
    </button>
  )
}
