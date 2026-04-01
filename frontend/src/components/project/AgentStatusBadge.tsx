/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useQuery } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { agentsApi } from '@/api/agents'
import { translationsApi } from '@/api/translations'

const STATUS_TRANSLATION_KEYS = [
  'agents.sheet.status.error',
  'agents.sheet.status.working',
  'agents.sheet.status.available',
] as const

/**
 * Fetches and displays the runtime status of a single agent.
 * Auto-refreshes every 30 seconds.
 *
 * @see agentsApi.getStatus
 */
export default function AgentStatusBadge({ agentId }: { agentId: string }) {
  const { data, isLoading } = useQuery({
    queryKey: ['agent-status', agentId],
    queryFn:  () => agentsApi.getStatus(agentId),
    refetchInterval: 30_000,
  })

  const { data: i18n } = useQuery({
    queryKey: ['ui-translations', 'agent-status-badge'],
    queryFn:  () => translationsApi.list([...STATUS_TRANSLATION_KEYS]),
    staleTime: Infinity,
  })

  const tt = (key: typeof STATUS_TRANSLATION_KEYS[number]) => i18n?.translations[key] ?? key

  if (isLoading) return <Loader2 className="w-3.5 h-3.5 animate-spin" style={{ color: 'var(--muted)' }} />

  const status = data?.status ?? 'idle'
  if (status === 'working') return <span className="badge-orange text-xs">{tt('agents.sheet.status.working')}</span>
  if (status === 'error')   return <span className="badge-red text-xs">{tt('agents.sheet.status.error')}</span>
  return <span className="badge-green text-xs">{tt('agents.sheet.status.available')}</span>
}
