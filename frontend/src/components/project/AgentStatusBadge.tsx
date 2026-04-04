/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useQuery } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { agentsApi } from '@/api/agents'
import { useTranslation } from '@/hooks/useTranslation'

const AGENT_STATUS_BADGE_TRANSLATION_KEYS = [
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

  const { t } = useTranslation(AGENT_STATUS_BADGE_TRANSLATION_KEYS)

  if (isLoading) return <Loader2 className="w-3.5 h-3.5 animate-spin" style={{ color: 'var(--muted)' }} />

  const status = data?.status ?? 'idle'
  if (status === 'working') return <span className="badge-orange text-xs">{t('agents.sheet.status.working')}</span>
  if (status === 'error')   return <span className="badge-red text-xs">{t('agents.sheet.status.error')}</span>
  return <span className="badge-green text-xs">{t('agents.sheet.status.available')}</span>
}
